import './bootstrap';

const normalizeImageUploadFile = async (file, maxDimension = 1600, quality = 0.82) => {
    if (!file || !file.type?.startsWith('image/') || file.type === 'image/gif') {
        return file;
    }

    const objectUrl = URL.createObjectURL(file);

    try {
        const image = new Image();
        image.decoding = 'async';
        await new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = () => reject(new Error('Unable to prepare this image for upload.'));
            image.src = objectUrl;
        });

        const sourceWidth = image.naturalWidth || image.width;
        const sourceHeight = image.naturalHeight || image.height;
        if (!sourceWidth || !sourceHeight) {
            return file;
        }

        const scale = Math.min(1, maxDimension / Math.max(sourceWidth, sourceHeight));
        const width = Math.max(1, Math.round(sourceWidth * scale));
        const height = Math.max(1, Math.round(sourceHeight * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d', { alpha: false });
        if (!context) {
            return file;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);

        const blob = await new Promise((resolve) => {
            canvas.toBlob(resolve, 'image/jpeg', quality);
        });

        if (!blob || blob.size <= 0) {
            return file;
        }

        const originalBase = (file.name || 'product-photo').replace(/\.[^.]+$/, '');
        return new File([blob], `${originalBase}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } catch (_) {
        return file;
    } finally {
        URL.revokeObjectURL(objectUrl);
    }
};

const jsonResponseOrText = async (response) => {
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        return response.json().catch(() => ({}));
    }

    return {
        _text: await response.text().catch(() => ''),
    };
};

const firstLaravelError = (payload) => {
    if (!payload || typeof payload !== 'object') return '';
    if (payload.message) return payload.message;
    const errors = Object.values(payload.errors || {}).flat().filter(Boolean);

    return errors[0] || '';
};

const RFM_BARCODE_MODE_KEY = 'rfm-barcode-input-mode';
const BARCODE_CAMERA_DETECT_MS = 180;

const cameraBarcodeSupported = () =>
    typeof window !== 'undefined'
    && 'BarcodeDetector' in window
    && !!navigator.mediaDevices?.getUserMedia;

const createCameraBarcodeSession = ({
    video,
    onDetected,
    onError,
    detectIntervalMs = BARCODE_CAMERA_DETECT_MS,
}) => {
    let stream = null;
    let timer = null;
    let detector = null;
    let closed = false;

    const close = () => {
        if (closed) return;
        closed = true;
        window.clearInterval(timer);
        timer = null;
        stream?.getTracks?.().forEach((track) => track.stop());
        stream = null;
        if (video) {
            video.srcObject = null;
        }
    };

    const start = async () => {
        if (!cameraBarcodeSupported()) {
            onError?.('Camera scanning is not supported in this browser. Use a hardware scanner or type the code.');
            return false;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play?.().catch(() => {});
            detector = new window.BarcodeDetector({
                formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'],
            });
            timer = window.setInterval(async () => {
                if (closed || video.readyState < 2) return;
                try {
                    const codes = await detector.detect(video);
                    const value = codes?.[0]?.rawValue || '';
                    if (value) {
                        onDetected(value);
                    }
                } catch {
                    onError?.('Barcode scanning failed. Use your scanner or type the code.');
                    close();
                }
            }, detectIntervalMs);
            return true;
        } catch {
            onError?.('Camera permission was blocked. Use your scanner or type the code.');
            close();
            return false;
        }
    };

    return { start, close };
};

const uploadFailureMessage = (response, payload, fallback = 'Unable to save image.', requestUrl = '') => {
    const serverMessage = firstLaravelError(payload);
    if (serverMessage) return serverMessage;

    const url = requestUrl || response.url || '';
    const urlHint = url ? ` Upload URL: ${url}` : '';

    if (response.status === 413) {
        return 'The photo is too large for the server upload limit. Try a smaller photo or increase upload limits on cPanel.';
    }

    if (response.status === 419) {
        return 'Your page session expired. Refresh the page, then try the photo again.';
    }

    if (response.status === 404) {
        return `${fallback} Server returned HTTP 404, so the upload route or selected product was not found.${urlHint}`;
    }

    if (response.status >= 500) {
        return 'The server failed while saving the image. Check storage/logs/laravel.log and the cPanel error log.';
    }

    const text = String(payload?._text || '').replace(/\s+/g, ' ').trim();
    if (text) {
        return `${fallback} Server returned HTTP ${response.status}: ${text.slice(0, 180)}${urlHint}`;
    }

    return `${fallback} Server returned HTTP ${response.status}.${urlHint}`;
};

const selectedMediaUploadFile = (cameraInput, uploadInput) => {
    if (cameraInput?.files?.length) {
        return cameraInput.files[0];
    }

    if (uploadInput?.files?.length) {
        return uploadInput.files[0];
    }

    return null;
};

const initBrandCarousels = () => {
    document.querySelectorAll('[data-brand-carousel]').forEach((modal) => {
        const slides = Array.from(modal.querySelectorAll('[data-carousel-slide]'));
        const triggers = Array.from(document.querySelectorAll('[data-brand-carousel-trigger]'));
        const closeButtons = Array.from(modal.querySelectorAll('[data-carousel-close]'));
        const prevButton = modal.querySelector('[data-carousel-prev]');
        const nextButton = modal.querySelector('[data-carousel-next]');
        const currentCounter = modal.querySelector('[data-carousel-current]');

        if (slides.length === 0 || triggers.length === 0) {
            return;
        }

        let currentIndex = 0;

        const setCurrentSlide = (index) => {
            currentIndex = (index + slides.length) % slides.length;

            slides.forEach((slide, slideIndex) => {
                const isActive = slideIndex === currentIndex;

                slide.hidden = !isActive;
                slide.classList.toggle('is-active', isActive);
            });

            if (currentCounter) {
                currentCounter.textContent = String(currentIndex + 1);
            }
        };

        const closeModal = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('is-modal-open');
        };

        const openModal = (index = 0) => {
            setCurrentSlide(index);
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('is-modal-open');
        };

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => {
                const requestedIndex = Number.parseInt(trigger.dataset.carouselIndex ?? '0', 10);
                openModal(Number.isNaN(requestedIndex) ? 0 : requestedIndex);
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                closeModal();
            });
        });

        prevButton?.addEventListener('click', () => {
            setCurrentSlide(currentIndex - 1);
        });

        nextButton?.addEventListener('click', () => {
            setCurrentSlide(currentIndex + 1);
        });

        document.addEventListener('keydown', (event) => {
            if (modal.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                closeModal();
            }

            if (event.key === 'ArrowLeft') {
                setCurrentSlide(currentIndex - 1);
            }

            if (event.key === 'ArrowRight') {
                setCurrentSlide(currentIndex + 1);
            }
        });
    });
};

const initPicturePreviewModal = () => {
    const modal = document.querySelector('[data-picture-preview-modal]');
    if (!modal) return;

    const backdrop = modal.querySelector('.pw-lightbox-backdrop');
    const img = modal.querySelector('[data-picture-preview-image]');
    if (!img) return;

    const closeButtons = Array.from(modal.querySelectorAll('[data-picture-preview-close]'));
    const title = modal.querySelector('[data-picture-preview-title]');
    const actions = modal.querySelector('[data-picture-preview-actions]');
    const replaceToggle = modal.querySelector('[data-picture-preview-replace-toggle]');
    const replacePanel = modal.querySelector('[data-picture-preview-replace-panel]');
    const replaceCancel = modal.querySelector('[data-picture-preview-replace-cancel]');
    const replaceTitle = modal.querySelector('[data-picture-preview-replace-title]');
    const replaceCurrent = modal.querySelector('[data-picture-preview-replace-current]');
    const replaceForm = modal.querySelector('[data-picture-preview-replace-form]');
    const replaceStatus = modal.querySelector('[data-picture-preview-replace-status]');
    const deleteForm = modal.querySelector('[data-picture-preview-delete-form]');
    const roleSelect = modal.querySelector('[data-picture-preview-role]');
    const usageSelect = modal.querySelector('[data-picture-preview-usage]');
    const sourceLabelInput = modal.querySelector('[data-picture-preview-source-label]');
    const notesInput = modal.querySelector('[data-picture-preview-notes]');
    const replaceMobileTargetInput = modal.querySelector('[data-picture-preview-replace-mobile-target-id]');
    let zoom = 1;
    let activeTrigger = null;
    const replaceTargetTypeInput = replaceForm?.querySelector('input[name="target_type"]');
    const replaceTargetIdInput = replaceForm?.querySelector('input[name="target_id"]');
    const replaceMobileTargetTypeInput = replaceForm?.querySelector('input[name="mobile_capture_target_type"]');

    const applyZoom = () => {
        img.style.transform = `scale(${zoom})`;
    };

    const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        img.src = '';
        activeTrigger = null;
        if (actions) actions.hidden = true;
        if (replacePanel) replacePanel.hidden = true;
        modal.classList.remove('is-replacing');
        document.body.classList.remove('is-modal-open');
        zoom = 1;
        applyZoom();
    };

    const setSelectValue = (select, value) => {
        if (!select || !value) return;
        if (Array.from(select.options).some((option) => option.value === value)) {
            select.value = value;
        }
    };

    const resetReplaceForm = () => {
        if (!replaceForm) return;
        replaceForm.reset();
        replaceForm.querySelectorAll('input[type="file"]').forEach((input) => {
            input.value = '';
        });
        if (replaceStatus) {
            replaceStatus.hidden = true;
            replaceStatus.textContent = '';
            replaceStatus.classList.remove('is-error');
        }
        if (replaceMobileTargetInput) {
            replaceMobileTargetInput.value = '';
        }
    };

    const openModal = (trigger) => {
        const url = trigger.dataset.imageUrl ?? '';
        if (!url) return;
        activeTrigger = trigger;
        zoom = 1;
        applyZoom();
        img.src = url;
        img.alt = trigger.dataset.pictureId ?? '';

        if (title) title.textContent = trigger.dataset.pictureId ?? 'Product image';

        const hasMediaActions = Boolean(trigger.dataset.imageDeleteUrl && trigger.dataset.imageReplaceUrl);
        if (actions) actions.hidden = !hasMediaActions;
        if (replacePanel) replacePanel.hidden = true;
        modal.classList.remove('is-replacing');
        if (replaceToggle) {
            replaceToggle.textContent = 'Replace';
            replaceToggle.classList.remove('is-active');
        }
        if (replaceTitle) replaceTitle.textContent = trigger.dataset.pictureId ?? 'Product image';
        if (replaceCurrent) {
            replaceCurrent.src = url;
            replaceCurrent.alt = trigger.dataset.pictureId ?? '';
        }

        if (hasMediaActions) {
            if (deleteForm) deleteForm.action = trigger.dataset.imageDeleteUrl;
            if (replaceForm) replaceForm.action = trigger.dataset.imageReplaceUrl;
            resetReplaceForm();
            if (replaceMobileTargetInput) replaceMobileTargetInput.value = trigger.dataset.mediaId || '';
            if (replaceTargetTypeInput) replaceTargetTypeInput.value = trigger.dataset.imageTargetType || '';
            if (replaceTargetIdInput) replaceTargetIdInput.value = trigger.dataset.imageTargetId || trigger.dataset.mediaId || '';
            if (replaceMobileTargetTypeInput) replaceMobileTargetTypeInput.value = '';
            setSelectValue(roleSelect, trigger.dataset.imageRole || 'main');
            setSelectValue(usageSelect, trigger.dataset.imageUsage || 'all');
            if (sourceLabelInput) sourceLabelInput.value = trigger.dataset.imageSourceLabel || '';
            if (notesInput) notesInput.value = trigger.dataset.imageNotes || '';
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('is-modal-open');
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-picture-preview-trigger]');
        if (!trigger) return;
        event.preventDefault();
        openModal(trigger);
    });
    backdrop?.addEventListener('click', closeModal);
    closeButtons.forEach((button) => button.addEventListener('click', closeModal));

    replaceToggle?.addEventListener('click', () => {
        if (!replacePanel || !activeTrigger) return;
        const willOpen = replacePanel.hidden;
        replacePanel.hidden = !willOpen;
        modal.classList.toggle('is-replacing', willOpen);
        replaceToggle.textContent = replacePanel.hidden ? 'Replace' : 'Cancel replace';
        replaceToggle.classList.toggle('is-active', !replacePanel.hidden);
        if (!replacePanel.hidden) {
            replacePanel.querySelector('[data-rfm-camera], [data-rfm-upload], input[type="url"]')?.focus();
        }
    });

    replaceCancel?.addEventListener('click', () => {
        if (replacePanel) replacePanel.hidden = true;
        modal.classList.remove('is-replacing');
        if (replaceToggle) {
            replaceToggle.textContent = 'Replace';
            replaceToggle.classList.remove('is-active');
        }
    });

    replaceForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeTrigger || !replaceForm.action || replaceForm.action.endsWith('#')) return;

        const submitButton = replaceForm.querySelector('button[type="submit"]');
        const setStatus = (message, isError = false) => {
            if (!replaceStatus) return;
            replaceStatus.hidden = !message;
            replaceStatus.textContent = message || '';
            replaceStatus.classList.toggle('is-error', isError);
        };

        try {
            submitButton?.setAttribute('disabled', 'disabled');
            setStatus('Replacing image...');

            const cameraInput = replaceForm.querySelector('[data-rfm-camera]');
            const uploadInput = replaceForm.querySelector('[data-rfm-upload]');
            const uploadFile = selectedMediaUploadFile(cameraInput, uploadInput);
            const payload = new FormData(replaceForm);
            if (uploadFile) {
                setStatus('Preparing image...');
                const preparedFile = await normalizeImageUploadFile(uploadFile);
                payload.set('uploaded_image', preparedFile, preparedFile.name || uploadFile.name);
            }
            payload.delete('uploaded_image_alt');

            const response = await fetch(replaceForm.action, {
                method: 'POST',
                body: payload,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await jsonResponseOrText(response);
            if (!response.ok) {
                throw new Error(uploadFailureMessage(response, data, 'Unable to replace image.', replaceForm.action));
            }

            setStatus('Image replaced.');
            if (window.LHCStyleWorkspace?.refreshAfterImageChange) {
                await window.LHCStyleWorkspace.refreshAfterImageChange();
                closeModal();
            } else {
                window.location.reload();
            }
        } catch (error) {
            setStatus(error.message || 'Unable to replace image.', true);
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });

    // Scroll to zoom — that's it
    deleteForm?.addEventListener('submit', async (event) => {
        if (event.defaultPrevented || !window.LHCStyleWorkspace?.refreshAfterImageChange) return;
        event.preventDefault();
        if (!activeTrigger || !deleteForm.action || deleteForm.action.endsWith('#')) return;

        const submitButton = deleteForm.querySelector('button[type="submit"]');
        try {
            submitButton?.setAttribute('disabled', 'disabled');
            const response = await fetch(deleteForm.action, {
                method: 'POST',
                body: new FormData(deleteForm),
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(`Unable to delete image (${response.status}).`);
            }

            await window.LHCStyleWorkspace.refreshAfterImageChange();
            closeModal();
        } catch (error) {
            window.LHCStyleWorkspace.showToast?.(error.message || 'Unable to delete image.', true);
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });

    modal.addEventListener('wheel', (e) => {
        e.preventDefault();
        zoom = e.deltaY < 0
            ? Math.min(zoom + 0.2, 6)
            : Math.max(zoom - 0.2, 0.2);
        applyZoom();
    }, { passive: false });

    // Click image to close when not zoomed
    img.addEventListener('click', () => {
        if (zoom <= 1) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (!modal.hidden && e.key === 'Escape') closeModal();
    });
};

const initSidebar = () => {
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('sidebar-collapse');
    const hamburgerBtn = document.getElementById('topnav-hamburger');
    const appMain = document.getElementById('app-main');
    const backdrop = document.getElementById('mobile-nav-backdrop');

    if (!sidebar) return;

    const mqMobile = window.matchMedia('(max-width: 767px)');
    const isMobile = () => mqMobile.matches;

    const isCollapsedDesktop = () => sidebar.classList.contains('is-collapsed');
    const isMobileDrawerOpen = () => sidebar.classList.contains('is-open');

    const setHamburgerExpanded = (open) => {
        if (!hamburgerBtn) return;
        hamburgerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        hamburgerBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    };

    const showBackdrop = () => {
        if (!backdrop || !isMobile()) return;
        backdrop.hidden = false;
        backdrop.setAttribute('aria-hidden', 'false');
        backdrop.classList.add('is-visible');
    };

    const hideBackdrop = () => {
        if (!backdrop) return;
        backdrop.classList.remove('is-visible');
        backdrop.setAttribute('aria-hidden', 'true');
        backdrop.hidden = true;
    };

    const openMobileDrawer = () => {
        sidebar.classList.add('is-open');
        document.body.classList.add('is-modal-open');
        showBackdrop();
        setHamburgerExpanded(true);
    };

    const closeMobileDrawer = () => {
        sidebar.classList.remove('is-open');
        document.body.classList.remove('is-modal-open');
        hideBackdrop();
        setHamburgerExpanded(false);
    };

    const collapseDesktop = () => {
        sidebar.classList.add('is-collapsed');
        sidebar.classList.remove('is-open');
        appMain?.classList.add('is-expanded');
        hideBackdrop();
        document.body.classList.remove('is-modal-open');
        setHamburgerExpanded(false);
        localStorage.setItem('sidebar-collapsed', '1');
    };

    const expandDesktop = () => {
        sidebar.classList.remove('is-collapsed');
        appMain?.classList.remove('is-expanded');
        localStorage.setItem('sidebar-collapsed', '0');
        if (!isMobile()) {
            setHamburgerExpanded(true);
        }
    };

    const toggleDesktop = () => {
        if (isCollapsedDesktop()) {
            expandDesktop();
        } else {
            collapseDesktop();
        }
    };

    hamburgerBtn?.addEventListener('click', () => {
        if (isMobile()) {
            if (isMobileDrawerOpen()) {
                closeMobileDrawer();
            } else {
                openMobileDrawer();
            }
        } else {
            toggleDesktop();
        }
    });

    collapseBtn?.addEventListener('click', () => {
        if (isMobile()) {
            closeMobileDrawer();
        } else {
            collapseDesktop();
        }
    });

    backdrop?.addEventListener('click', () => {
        if (isMobile() && isMobileDrawerOpen()) {
            closeMobileDrawer();
        }
    });

    sidebar.querySelectorAll('a.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (isMobile()) {
                closeMobileDrawer();
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (isMobile() && isMobileDrawerOpen()) {
            closeMobileDrawer();
            return;
        }
        if (!isMobile() && !isCollapsedDesktop()) {
            collapseDesktop();
        }
    });

    if (!isMobile()) {
        if (localStorage.getItem('sidebar-collapsed') === '0') {
            expandDesktop();
        } else {
            collapseDesktop();
        }
    }

    const mqNarrow = window.matchMedia('(max-width: 1023px)');
    const syncSidebarForViewport = () => {
        if (mqNarrow.matches && !isMobile() && !isCollapsedDesktop()) {
            collapseDesktop();
        }
    };

    syncSidebarForViewport();
    mqNarrow.addEventListener('change', syncSidebarForViewport);

    mqMobile.addEventListener('change', () => {
        if (!isMobile()) {
            closeMobileDrawer();
        } else {
            hideBackdrop();
            if (!isMobileDrawerOpen()) {
                setHamburgerExpanded(false);
            }
        }
    });
};

const initPictureViewToggle = () => {
    const toggle = document.getElementById('pw-view-toggle');
    const grid = document.getElementById('pw-grid');
    if (!toggle || !grid) return;

    const buttons = toggle.querySelectorAll('.pw-vt-btn');
    const saved = localStorage.getItem('pw-view') || 'grid';

    const setView = (view) => {
        buttons.forEach(b => b.classList.toggle('is-active', b.dataset.view === view));
        grid.classList.toggle('pw-grid-list', view === 'list');
        localStorage.setItem('pw-view', view);
    };

    setView(saved);

    buttons.forEach(btn => {
        btn.addEventListener('click', () => setView(btn.dataset.view));
    });
};

const initDeliverooBrandFilter = () => {
    const root = document.querySelector('[data-deliveroo-brand-filter]');
    if (!root) return;

    const search = root.querySelector('[data-deliveroo-brand-search]');
    const count = root.querySelector('[data-deliveroo-brand-count]');
    const cards = Array.from(document.querySelectorAll('[data-deliveroo-brand-card]'));
    const sections = Array.from(document.querySelectorAll('[data-deliveroo-category-section]'));
    const categoryButtons = Array.from(root.querySelectorAll('[data-deliveroo-category-filter]'));

    let activeCategory = 'all';

    const apply = () => {
        const term = (search?.value || '').trim().toLowerCase();
        let visibleCards = 0;

        cards.forEach((card) => {
            const category = card.dataset.deliverooCategory || '';
            const haystack = card.dataset.deliverooSearch || '';
            const categoryOk = activeCategory === 'all' || category === activeCategory;
            const searchOk = term === '' || haystack.includes(term);
            const visible = categoryOk && searchOk;
            card.hidden = !visible;
            if (visible) visibleCards += 1;
        });

        sections.forEach((section) => {
            const hasVisibleCard = section.querySelector('[data-deliveroo-brand-card]:not([hidden])');
            section.hidden = !hasVisibleCard;
        });

        if (count) {
            count.textContent = visibleCards === cards.length && term === '' && activeCategory === 'all'
                ? 'Showing all brands'
                : `Showing ${visibleCards} brand${visibleCards === 1 ? '' : 's'}`;
        }
    };

    search?.addEventListener('input', apply);
    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeCategory = button.dataset.deliverooCategoryFilter || 'all';
            categoryButtons.forEach((b) => b.classList.toggle('is-active', b === button));
            apply();
        });
    });
    apply();
};

const initDeliverooProductFilter = () => {
    const root = document.querySelector('[data-deliveroo-product-filter]');
    if (!root) return;

    const search = root.querySelector('[data-deliveroo-product-search]');
    const count = root.querySelector('[data-deliveroo-product-count]');
    const cards = Array.from(document.querySelectorAll('[data-deliveroo-product-card]'));

    const apply = () => {
        const term = (search?.value || '').trim().toLowerCase();
        let visibleCards = 0;
        cards.forEach((card) => {
            const haystack = card.dataset.deliverooSearch || '';
            const visible = term === '' || haystack.includes(term);
            card.hidden = !visible;
            if (visible) visibleCards += 1;
        });
        if (count) {
            count.textContent = visibleCards === cards.length && term === ''
                ? 'Showing all products'
                : `Showing ${visibleCards} product${visibleCards === 1 ? '' : 's'}`;
        }
    };

    search?.addEventListener('input', apply);
    apply();
};

const initDeliverooProductGallery = () => {
    const mainImage = document.querySelector('[data-deliveroo-main-image]');
    if (!mainImage) return;

    const thumbs = Array.from(document.querySelectorAll('[data-deliveroo-thumb]'));
    const gallery = thumbs.map((t) => t.dataset.deliverooThumb).filter(Boolean);
    if (gallery.length === 0) gallery.push(mainImage.src);

    let currentIndex = 0;

    const setActiveThumb = (url) => {
        thumbs.forEach((t) => t.classList.toggle('is-active', t.dataset.deliverooThumb === url));
    };

    const showImage = (index) => {
        currentIndex = ((index % gallery.length) + gallery.length) % gallery.length;
        mainImage.src = gallery[currentIndex];
        setActiveThumb(gallery[currentIndex]);
    };

    thumbs.forEach((thumb) => {
        thumb.addEventListener('click', () => {
            const idx = Number.parseInt(thumb.dataset.galleryIndex ?? '0', 10);
            showImage(Number.isNaN(idx) ? 0 : idx);
        });
    });

    setActiveThumb(mainImage.src);

    const lightbox = document.querySelector('[data-deliveroo-lightbox]');
    if (!lightbox) return;

    const lbImg = lightbox.querySelector('[data-deliveroo-lightbox-img]');
    const lbCounter = lightbox.querySelector('[data-deliveroo-lightbox-counter]');
    const lbPrev = lightbox.querySelector('[data-deliveroo-lightbox-prev]');
    const lbNext = lightbox.querySelector('[data-deliveroo-lightbox-next]');
    const lbCloseButtons = Array.from(lightbox.querySelectorAll('[data-deliveroo-lightbox-close]'));
    const lbOpenButtons = Array.from(document.querySelectorAll('[data-deliveroo-lightbox-open]'));
    const heroFrame = document.querySelector('[data-deliveroo-main-frame]');

    const updateLightbox = () => {
        if (!lbImg) return;
        lbImg.src = gallery[currentIndex];
        if (lbCounter && gallery.length > 1) {
            lbCounter.textContent = `${currentIndex + 1} / ${gallery.length}`;
        }
    };

    const openLightbox = () => {
        updateLightbox();
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.classList.add('is-modal-open');
    };

    const closeLightbox = () => {
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('is-modal-open');
    };

    lbOpenButtons.forEach((b) => b.addEventListener('click', openLightbox));
    heroFrame?.addEventListener('click', (e) => {
        if (e.target === mainImage) openLightbox();
    });
    lbCloseButtons.forEach((b) => b.addEventListener('click', closeLightbox));

    lbPrev?.addEventListener('click', () => { showImage(currentIndex - 1); updateLightbox(); });
    lbNext?.addEventListener('click', () => { showImage(currentIndex + 1); updateLightbox(); });

    document.addEventListener('keydown', (e) => {
        if (lightbox.hidden) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') { showImage(currentIndex - 1); updateLightbox(); }
        if (e.key === 'ArrowRight') { showImage(currentIndex + 1); updateLightbox(); }
    });
};

const deliverooImageUrlLooksValid = (value) => {
    try {
        const u = new URL(value);
        return u.protocol === 'http:' || u.protocol === 'https:';
    } catch {
        return false;
    }
};

const debounceDeliveroo = (fn, ms) => {
    let t = null;
    return (...args) => {
        if (t) clearTimeout(t);
        t = setTimeout(() => {
            t = null;
            fn(...args);
        }, ms);
    };
};

const initDeliverooImageUrlRows = () => {
    document.querySelectorAll('[data-deliveroo-image-rows]').forEach((root) => {
        const list = root.querySelector('[data-image-rows-list]');
        const template = root.querySelector('[data-image-row-template]');
        const addBtn = root.querySelector('[data-image-row-add]');
        if (!list || !template || !addBtn) return;

        const maxRows = Math.min(40, Math.max(1, Number.parseInt(root.dataset.maxRows || '40', 10) || 40));
        const uploadUrl = root.dataset.uploadUrl || '';
        const uploadingLabel = root.dataset.uploadingLabel || 'Uploading image...';
        const uploadSuccessLabel = root.dataset.uploadSuccessLabel || 'Image uploaded.';
        const uploadErrorLabel = root.dataset.uploadErrorLabel || 'Unable to upload image.';
        const uploadInvalidLabel = root.dataset.uploadInvalidLabel || 'Clipboard paste did not contain an image file.';
        const uploadFileInvalidLabel = root.dataset.uploadFileInvalidLabel || 'The selected file is not a valid image.';
        const uploadMaxedLabel = root.dataset.uploadMaxedLabel || 'All image slots are already used.';
        const pasteTarget = root.querySelector('[data-image-paste-target]');
        const fileTrigger = root.querySelector('[data-image-file-trigger]');
        const fileInput = root.querySelector('[data-image-file-input]');
        const pasteStatus = root.querySelector('[data-image-paste-status]');
        const csrfToken = root.closest('form')?.querySelector('input[name="_token"]')?.value || '';
        const labelMain = root.dataset.labelMain || 'Image 1 — main';
        const labelExtraTpl = root.dataset.labelExtra || 'Image :number';
        const emptyPreview = root.dataset.previewEmpty || 'No preview yet';
        const brokenPreview = root.dataset.previewBroken || 'Preview unavailable';

        const labelForIndex = (i) => (i === 0 ? labelMain : labelExtraTpl.replace(':number', String(i + 1)));

        let draggedRow = null;

        const refreshLabels = () => {
            const rows = Array.from(list.querySelectorAll('[data-image-row]'));
            rows.forEach((row, i) => {
                const el = row.querySelector('[data-image-row-label]');
                if (el) el.textContent = labelForIndex(i);
                const up = row.querySelector('[data-image-row-up]');
                const down = row.querySelector('[data-image-row-down]');
                if (up) up.disabled = i === 0;
                if (down) down.disabled = i === rows.length - 1;
            });
            addBtn.disabled = rows.length >= maxRows;
        };

        const updatePreview = (row) => {
            const input = row.querySelector('[data-image-url-input]');
            const img = row.querySelector('[data-image-preview]');
            const fallback = row.querySelector('[data-image-preview-fallback]');
            const val = (input?.value || '').trim();
            if (!img || !fallback) return;

            const showFallback = (text) => {
                img.removeAttribute('src');
                img.hidden = true;
                fallback.hidden = false;
                fallback.textContent = text;
            };

            img.onerror = () => showFallback(brokenPreview);
            img.onload = () => {
                img.hidden = false;
                fallback.hidden = true;
            };

            if (val === '' || !deliverooImageUrlLooksValid(val)) {
                showFallback(val === '' ? emptyPreview : brokenPreview);
                return;
            }

            if (img.getAttribute('src') === val) {
                return;
            }
            img.alt = '';
            img.src = val;
        };

        const debouncedPreview = debounceDeliveroo((row) => updatePreview(row), 280);

        const setPasteStatus = (message, isError = false) => {
            if (!pasteStatus) return;

            if (!message) {
                pasteStatus.hidden = true;
                pasteStatus.textContent = '';
                pasteStatus.classList.remove('is-error');
                return;
            }

            pasteStatus.hidden = false;
            pasteStatus.textContent = message;
            pasteStatus.classList.toggle('is-error', isError);
        };

        const findEmptyRow = () => Array.from(list.querySelectorAll('[data-image-row]')).find((row) => {
            const input = row.querySelector('[data-image-url-input]');
            return ((input?.value || '').trim()) === '';
        }) || null;

        const setRowImageUrl = (row, url) => {
            const input = row?.querySelector('[data-image-url-input]');
            if (!input) return;
            input.value = url;
            updatePreview(row);
            refreshLabels();
            input.focus();
            if (typeof input.select === 'function') {
                input.select();
            }
        };

        const setUploadBusyState = (isBusy) => {
            pasteTarget?.classList.toggle('is-uploading', isBusy);
            if (fileTrigger) {
                fileTrigger.classList.toggle('is-uploading', isBusy);
                fileTrigger.disabled = isBusy;
            }
            if (fileInput) {
                fileInput.disabled = isBusy;
            }
        };

        const wireRow = (row) => {
            const input = row.querySelector('[data-image-url-input]');
            input?.addEventListener('input', () => debouncedPreview(row));
            input?.addEventListener('blur', () => updatePreview(row));

            row.querySelector('[data-image-row-remove]')?.addEventListener('click', () => {
                const rows = list.querySelectorAll('[data-image-row]');
                if (rows.length <= 1) {
                    if (input) input.value = '';
                    updatePreview(row);
                    return;
                }
                row.remove();
                refreshLabels();
            });

            row.querySelector('[data-image-row-up]')?.addEventListener('click', () => {
                const prev = row.previousElementSibling;
                if (prev) {
                    list.insertBefore(row, prev);
                    refreshLabels();
                }
            });

            row.querySelector('[data-image-row-down]')?.addEventListener('click', () => {
                const next = row.nextElementSibling;
                if (next) {
                    list.insertBefore(row, next.nextElementSibling);
                    refreshLabels();
                }
            });

            row.addEventListener('dragstart', (e) => {
                draggedRow = row;
                row.classList.add('is-dragging');
                e.dataTransfer?.setData('text/plain', 'deliveroo-image-row');
                if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                draggedRow = null;
                list.querySelectorAll('[data-image-row]').forEach((r) => r.classList.remove('is-drag-over'));
            });
            row.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!draggedRow || draggedRow === row) return;
                list.querySelectorAll('[data-image-row]').forEach((r) => r.classList.remove('is-drag-over'));
                row.classList.add('is-drag-over');
            });
            row.addEventListener('dragleave', () => row.classList.remove('is-drag-over'));
            row.addEventListener('drop', (e) => {
                e.preventDefault();
                row.classList.remove('is-drag-over');
                if (!draggedRow || draggedRow === row) return;
                const rect = row.getBoundingClientRect();
                const before = e.clientY - rect.top < rect.height / 2;
                if (before) {
                    list.insertBefore(draggedRow, row);
                } else {
                    list.insertBefore(draggedRow, row.nextElementSibling);
                }
                refreshLabels();
            });
        };

        const addRow = () => {
            if (list.querySelectorAll('[data-image-row]').length >= maxRows) return null;
            const frag = template.content.cloneNode(true);
            const row = frag.querySelector('[data-image-row]');
            if (!row) return null;
            list.appendChild(row);
            wireRow(row);
            updatePreview(row);
            refreshLabels();
            return row;
        };

        const uploadImageFile = async (file, invalidMessage) => {
            if (!uploadUrl) return;

            if (!file || !String(file.type || '').startsWith('image/')) {
                setPasteStatus(invalidMessage, true);
                return;
            }

            setUploadBusyState(true);
            setPasteStatus(uploadingLabel);

            try {
                const formData = new FormData();
                if (csrfToken) {
                    formData.append('_token', csrfToken);
                }

                const extension = String(file.type || 'image/png')
                    .split('/')[1]
                    ?.replace(/[^a-z0-9]+/gi, '')
                    .toLowerCase() || 'png';
                formData.append('uploaded_image', file, `upload-${Date.now()}.${extension}`);

                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload?.url) {
                    throw new Error(payload?.message || uploadErrorLabel);
                }

                const targetRow = findEmptyRow() || addRow();
                if (!targetRow) {
                    throw new Error(uploadMaxedLabel);
                }

                setRowImageUrl(targetRow, payload.url);
                setPasteStatus(uploadSuccessLabel);
            } catch (error) {
                const message = error instanceof Error && error.message ? error.message : uploadErrorLabel;
                setPasteStatus(message, true);
            } finally {
                setUploadBusyState(false);
                if (fileInput) {
                    fileInput.value = '';
                }
            }
        };

        list.querySelectorAll('[data-image-row]').forEach((row) => {
            wireRow(row);
            updatePreview(row);
        });
        refreshLabels();

        addBtn.addEventListener('click', addRow);

        if (pasteTarget && uploadUrl) {
            pasteTarget.addEventListener('click', () => pasteTarget.focus());
            pasteTarget.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    pasteTarget.focus();
                }
            });
            pasteTarget.addEventListener('paste', (event) => {
                const items = Array.from(event.clipboardData?.items || []);
                const imageItem = items.find((item) => String(item.type || '').startsWith('image/'));
                if (!imageItem) {
                    setPasteStatus(uploadInvalidLabel, true);
                    return;
                }

                const file = imageItem.getAsFile();
                if (!file) {
                    setPasteStatus(uploadInvalidLabel, true);
                    return;
                }

                event.preventDefault();
                uploadImageFile(file, uploadInvalidLabel);
            });
        }

        if (fileTrigger && fileInput && uploadUrl) {
            fileTrigger.addEventListener('click', () => {
                fileInput.click();
            });

            fileInput.addEventListener('change', () => {
                const [file] = Array.from(fileInput.files || []);
                if (!file) {
                    return;
                }

                uploadImageFile(file, uploadFileInvalidLabel);
            });
        }
    });
};

const initDeliverooManualProductForm = () => {
    const root = document.querySelector('[data-deliveroo-manual-form]');
    if (!root) return;

    const familiesUrl = root.dataset.familiesUrl || '';
    const brandSelect = root.querySelector('#deliveroo-manual-brand');
    const brandExistingPanel = root.querySelector('[data-deliveroo-brand-existing-panel]');
    const brandNewPanel = root.querySelector('[data-deliveroo-brand-new-panel]');
    const brandRadios = Array.from(root.querySelectorAll('input[name="brand_mode"]'));
    const familySelect = root.querySelector('#deliveroo-manual-family-select');
    const existingPanel = root.querySelector('[data-deliveroo-family-existing-panel]');
    const newPanel = root.querySelector('[data-deliveroo-family-new-panel]');
    const radios = Array.from(root.querySelectorAll('input[name="family_link"]'));
    const placeholder = familySelect?.dataset.placeholder || '';
    const oldFamily = root.dataset.oldFamilyExisting || '';

    const syncBrandPanels = () => {
        const mode = brandRadios.find((r) => r.checked)?.value || 'existing';
        if (brandExistingPanel) brandExistingPanel.hidden = mode !== 'existing';
        if (brandNewPanel) brandNewPanel.hidden = mode !== 'new';
        if (brandSelect) brandSelect.disabled = mode !== 'existing';
    };

    const syncPanels = () => {
        const mode = radios.find((r) => r.checked)?.value || 'none';
        if (existingPanel) existingPanel.hidden = mode !== 'existing';
        if (newPanel) newPanel.hidden = mode !== 'new';
        if (familySelect) familySelect.disabled = mode !== 'existing';
    };

    const loadFamilies = async () => {
        if (!brandSelect || !familySelect || !familiesUrl) return;
        const brandMode = brandRadios.find((r) => r.checked)?.value || 'existing';
        const slug = brandSelect.value;
        familySelect.innerHTML = '';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        familySelect.appendChild(opt0);

        if (brandMode !== 'existing' || !slug) {
            return;
        }

        try {
            const res = await fetch(`${familiesUrl}?brand_slug=${encodeURIComponent(slug)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                return;
            }
            const data = await res.json();
            const families = Array.isArray(data.families) ? data.families : [];
            families.forEach((name) => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (oldFamily && name === oldFamily) {
                    opt.selected = true;
                }
                familySelect.appendChild(opt);
            });
        } catch {
            /* keep placeholder only */
        }
    };

    brandRadios.forEach((r) => r.addEventListener('change', () => {
        syncBrandPanels();
        loadFamilies();
    }));
    radios.forEach((r) => r.addEventListener('change', syncPanels));
    brandSelect?.addEventListener('change', () => {
        loadFamilies();
    });

    syncBrandPanels();
    syncPanels();
    if ((brandRadios.find((r) => r.checked)?.value || 'existing') === 'existing' && brandSelect?.value) {
        loadFamilies();
    }
};

const initDeliverooBrandCatalogue = () => {
    const root = document.querySelector('[data-deliveroo-brand-catalogue]');
    if (!root) return;

    const toolbar = root.querySelector('[data-deliveroo-catalogue-bulk-toolbar]');
    const summary = root.querySelector('[data-deliveroo-catalogue-selected-summary]');
    const clearBtn = root.querySelector('[data-deliveroo-catalogue-clear]');
    const bulkDeleteBtn = root.querySelector('[data-deliveroo-catalogue-bulk-delete]');
    const bulkForm = root.querySelector('[data-deliveroo-catalogue-bulk-form]');
    const bulkInputsHost = root.querySelector('[data-deliveroo-catalogue-bulk-inputs]');
    const labelTpl = root.dataset.selectedLabelTemplate || '%N% selected';
    const deleteOneConfirm = root.dataset.deleteOneConfirm || '';
    const bulkDeleteConfirmTpl = root.dataset.bulkDeleteConfirm || '';

    const cards = () => Array.from(root.querySelectorAll('[data-deliveroo-catalogue-card]'));

    const selectedIds = () =>
        cards()
            .map((card) => {
                const cb = card.querySelector('[data-deliveroo-catalogue-select]');
                return cb?.checked ? String(card.dataset.productId || '') : null;
            })
            .filter(Boolean);

    const isSelectionMode = () => selectedIds().length > 0;

    /**
     * When at least one product is selected, body/media clicks toggle selection
     * instead of opening the product (chrome + price + footer buttons excluded).
     */
    const cardFromSelectionToggleClick = (target) => {
        const card = target.closest('[data-deliveroo-catalogue-card]');
        if (!card || !root.contains(card)) return null;
        if (target.closest('[data-deliveroo-catalogue-chrome]')) return null;
        if (target.closest('.button-row')) return null;
        if (target.closest('[data-deliveroo-price-modal]')) return null;
        if (target.closest('[data-deliveroo-price-open]')) return null;
        if (target.closest('button, input, textarea, select, label')) return null;
        return card;
    };

    const sync = () => {
        const ids = selectedIds();
        const n = ids.length;

        cards().forEach((card) => {
            const cb = card.querySelector('[data-deliveroo-catalogue-select]');
            card.classList.toggle('is-catalogue-selected', Boolean(cb?.checked));
        });

        if (summary) {
            summary.textContent = labelTpl.replace('%N%', String(n));
        }
        if (toolbar) {
            toolbar.hidden = n === 0;
        }
        root.classList.toggle('is-catalogue-selection-mode', n > 0);
    };

    root.addEventListener(
        'click',
        (e) => {
            if (!isSelectionMode()) return;
            const card = cardFromSelectionToggleClick(e.target);
            if (!card) return;
            const cb = card.querySelector('[data-deliveroo-catalogue-select]');
            if (!cb) return;
            e.preventDefault();
            e.stopPropagation();
            cb.checked = !cb.checked;
            sync();
        },
        true,
    );

    root.addEventListener('change', (e) => {
        const t = e.target;
        if (t && t.matches('[data-deliveroo-catalogue-select]')) {
            sync();
        }
    });

    clearBtn?.addEventListener('click', () => {
        root.querySelectorAll('[data-deliveroo-catalogue-select]').forEach((cb) => {
            cb.checked = false;
        });
        sync();
    });

    root.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form || !form.matches('[data-deliveroo-catalogue-delete-one]')) return;
        e.preventDefault();
        if (!deleteOneConfirm || window.confirm(deleteOneConfirm)) {
            form.submit();
        }
    });

    bulkDeleteBtn?.addEventListener('click', () => {
        const ids = selectedIds();
        if (ids.length === 0 || !bulkForm || !bulkInputsHost) return;
        const msg = bulkDeleteConfirmTpl.replace('%N%', String(ids.length));
        if (!window.confirm(msg)) return;
        bulkInputsHost.innerHTML = '';
        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = id;
            bulkInputsHost.appendChild(input);
        });
        bulkForm.submit();
    });

    sync();
};

const initDeliverooCatalogueLayout = () => {
    const hosts = Array.from(document.querySelectorAll('[data-deliveroo-catalogue-grid-host]'));
    if (hosts.length === 0) return;

    const validViews = new Set(['grid', 'list']);
    const validCols = new Set(['4', '6', '8']);

    hosts.forEach((host) => {
        const root = host.closest('[data-deliveroo-brand-catalogue]') ?? host.parentElement;
        if (!root) return;

        const scope = root.dataset.catalogueStorageScope || 'default';
        const keyView = `deliveroo.catalogue.${scope}.view`;
        const keyCols = `deliveroo.catalogue.${scope}.cols`;

        const viewButtons = Array.from(root.querySelectorAll('[data-catalogue-layout-view]'));
        const colButtons = Array.from(root.querySelectorAll('[data-catalogue-layout-cols]'));
        const densityWrap = root.querySelector('[data-catalogue-layout-density-wrap]');

        const applyView = (view, persist) => {
            const v = validViews.has(view) ? view : 'grid';
            host.setAttribute('data-view', v);
            if (persist) {
                try {
                    localStorage.setItem(keyView, v);
                } catch {
                    /* ignore */
                }
            }
            viewButtons.forEach((btn) => {
                const on = btn.getAttribute('data-catalogue-layout-view') === v;
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (densityWrap) {
                densityWrap.hidden = v === 'list';
            }
        };

        const applyCols = (cols, persist) => {
            const c = validCols.has(String(cols)) ? String(cols) : '4';
            host.setAttribute('data-cols', c);
            if (persist) {
                try {
                    localStorage.setItem(keyCols, c);
                } catch {
                    /* ignore */
                }
            }
            colButtons.forEach((btn) => {
                const on = btn.getAttribute('data-catalogue-layout-cols') === c;
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        };

        let storedView = null;
        let storedCols = null;
        try {
            storedView = localStorage.getItem(keyView);
            storedCols = localStorage.getItem(keyCols);
        } catch {
            /* ignore */
        }

        if (storedView && validViews.has(storedView)) {
            applyView(storedView, false);
        } else {
            applyView(host.dataset.view || 'grid', false);
        }

        if (storedCols && validCols.has(storedCols)) {
            applyCols(storedCols, false);
        } else {
            applyCols(host.dataset.cols || '4', false);
        }

        root.addEventListener('click', (e) => {
            const viewBtn = e.target.closest('[data-catalogue-layout-view]');
            if (viewBtn && root.contains(viewBtn)) {
                const v = viewBtn.getAttribute('data-catalogue-layout-view');
                if (v && validViews.has(v)) {
                    applyView(v, true);
                }
                return;
            }
            const colBtn = e.target.closest('[data-catalogue-layout-cols]');
            if (colBtn && root.contains(colBtn)) {
                const c = colBtn.getAttribute('data-catalogue-layout-cols');
                if (c && validCols.has(c)) {
                    applyCols(c, true);
                }
            }
        });
    });
};

const initDeliverooPriceModal = () => {
    const scopes = Array.from(document.querySelectorAll('[data-deliveroo-price-scope]'));
    if (scopes.length === 0) return;

    scopes.forEach((scope) => {
        const modal = scope.querySelector('[data-deliveroo-price-modal]');
        const openButtons = Array.from(scope.querySelectorAll('[data-deliveroo-price-open]'));
        const closeButtons = Array.from(scope.querySelectorAll('[data-deliveroo-price-close]'));
        const backdrop = modal?.querySelector('.deliveroo-price-modal-backdrop');
        const form = scope.querySelector('[data-deliveroo-price-form]');
        const title = scope.querySelector('[data-deliveroo-price-modal-title]');
        const errorBox = scope.querySelector('[data-deliveroo-price-error]');
        const priceInput = scope.querySelector('[data-deliveroo-price-input]');
        const noteInput = scope.querySelector('[data-deliveroo-price-note-input]');
        const submitButton = scope.querySelector('[data-deliveroo-price-submit]');
        const priceDisplay = scope.querySelector('[data-deliveroo-price-display]');
        const priceNote = scope.querySelector('[data-deliveroo-price-note]');
        const filledState = scope.querySelector('[data-deliveroo-price-filled]');
        const emptyState = scope.querySelector('[data-deliveroo-price-empty]');

        if (!modal || !form || !title || !priceInput || !noteInput || !submitButton || !priceDisplay) {
            return;
        }

        const closeModal = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('is-modal-open');
            if (errorBox) {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }
            if (modal.parentElement === document.body) {
                scope.appendChild(modal);
            }
        };

        const openModal = (trigger) => {
            const hasPrice = trigger?.dataset.hasPrice === '1';
            title.textContent = hasPrice ? 'Edit Deliveroo Price' : 'Add Deliveroo Price';
            document.body.appendChild(modal);
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('is-modal-open');
            setTimeout(() => priceInput.focus(), 30);
        };

        const setPriceButtonState = ({ hasPrice }) => {
            openButtons.forEach((button) => {
                button.dataset.hasPrice = hasPrice ? '1' : '0';
                button.setAttribute('aria-label', hasPrice ? 'Edit price' : 'Add price');
                button.setAttribute('title', hasPrice ? 'Edit price' : 'Add price');

                if (hasPrice) {
                    button.className = 'deliveroo-price-edit-button';
                    button.innerHTML = '<span aria-hidden="true">✎</span>';
                } else {
                    button.className = 'button button-primary deliveroo-price-add-button';
                    button.textContent = 'Add Price';
                }
            });
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => openModal(button));
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        backdrop?.addEventListener('click', closeModal);

        document.addEventListener('keydown', (event) => {
            if (!modal.hidden && event.key === 'Escape') {
                closeModal();
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (errorBox) {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            const originalLabel = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';

            try {
                const formData = new FormData(form);
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json();

                if (!response.ok) {
                    const message = payload?.message
                        || Object.values(payload?.errors || {}).flat()[0]
                        || 'Unable to save price.';

                    throw new Error(message);
                }

                const product = payload.product || {};
                priceDisplay.textContent = product.price_display || 'Not set';

                if (priceNote) {
                    priceNote.textContent = product.price_notes || '';
                    priceNote.hidden = !product.price_notes;
                }

                if (filledState) {
                    filledState.hidden = !product.has_price;
                }

                if (emptyState) {
                    emptyState.hidden = !!product.has_price;
                }

                setPriceButtonState({ hasPrice: !!product.has_price });
                closeModal();
            } catch (error) {
                if (errorBox) {
                    errorBox.hidden = false;
                    errorBox.textContent = error.message || 'Unable to save price.';
                }
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel;
            }
        });
    });
};

// ═══════════════════════════════════════════════════
// Style Workspace — AJAX form handling
// Submits forms without page reload, refreshes only the changed section
// ═══════════════════════════════════════════════════
const initStyleWorkspaceAjax = () => {
    const workspace = document.querySelector('.sw-workspace');
    if (!workspace) return;

    // Toast helper
    const showToast = (msg, isError = false) => {
        let toast = document.getElementById('sw-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'sw-toast';
            toast.className = 'sw-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.classList.toggle('sw-toast-error', isError);
        toast.classList.add('sw-toast-show');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.classList.remove('sw-toast-show'), 2500);
    };

    // Fetch the current page and extract fresh HTML for a selector
    const fetchFreshContent = async (selector) => {
        const res = await fetch(window.location.href);
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.querySelector(selector);
    };

    // Refresh a variant card body after change
    const refreshVariantCard = async (variantCardEl) => {
        const cardId = variantCardEl.id; // e.g. "vg-123"
        if (!cardId) return;
        const fresh = await fetchFreshContent(`#${cardId}`);
        if (fresh) {
            // Preserve collapsed state
            const wasCollapsed = variantCardEl.classList.contains('is-collapsed');
            variantCardEl.innerHTML = fresh.innerHTML;
            if (wasCollapsed) variantCardEl.classList.add('is-collapsed');
            // Re-bind the header toggle
            const header = variantCardEl.querySelector('.sw-variant-header');
            if (header) {
                header.addEventListener('click', () => variantCardEl.classList.toggle('is-collapsed'));
            }
            initRetailMediaManagers();
        }
    };

    // Refresh sidebar (stats + images)
    const refreshSidebar = async () => {
        const fresh = await fetchFreshContent('.sw-sidebar');
        const sidebar = document.querySelector('.sw-sidebar');
        if (fresh && sidebar) {
            sidebar.innerHTML = fresh.innerHTML;
            initRetailMediaManagers();
        }
    };

    // Refresh the SKU section
    const refreshSkuSection = async () => {
        // SKU section is the second .sw-section in .sw-main
        const sections = document.querySelectorAll('.sw-main .sw-section');
        if (sections.length < 2) return;
        const freshDoc = await fetch(window.location.href).then(r => r.text()).then(html => new DOMParser().parseFromString(html, 'text/html'));
        const freshSections = freshDoc.querySelectorAll('.sw-main .sw-section');
        if (freshSections.length >= 2) {
            sections[1].innerHTML = freshSections[1].innerHTML;
        }
    };

    // Main handler — intercept form submissions
    workspace.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        // Don't handle the variant group add form — it needs a full reload to add a whole new card
        if (form.classList.contains('sw-quick-add')) return;

        e.preventDefault();

        const method = (form.querySelector('input[name="_method"]')?.value || form.method).toUpperCase();
        const action = form.action;
        const submitBtn = form.querySelector('button[type="submit"]');
        const skipSubmitLoading = submitBtn?.classList.contains('sw-opt-summary-del-btn');
        const originalText = submitBtn?.textContent;

        // Loading state
        if (submitBtn && ! skipSubmitLoading) {
            submitBtn.disabled = true;
            submitBtn.textContent = '...';
        }

        try {
            const formData = new FormData(form);
            const fetchOpts = { method: 'POST', body: formData, redirect: 'manual' };

            const res = await fetch(action, fetchOpts);

            // Laravel returns 302 redirect on success, or 200 if it's a direct response
            if (res.type === 'opaqueredirect' || res.status === 302 || res.status === 200 || res.status === 0) {
                // Determine what to refresh based on context
                const variantCard = form.closest('.sw-variant-card');
                const isInSidebar = form.closest('.sw-sidebar');
                const isSkuForm = form.closest('.sw-sku-edit-detail') || form.closest('.sw-add-drawer-lg');

                if (method === 'DELETE' && !isInSidebar && !isSkuForm) {
                    // Deleting an option or option image
                    const optCard = form.closest('.sw-opt-card');
                    const assetItem = form.closest('.sw-opt-asset-item');

                    if (assetItem) {
                        assetItem.remove();
                        showToast('Image deleted');
                        // Update badge counts
                        if (variantCard) await refreshVariantCard(variantCard);
                    } else if (optCard) {
                        optCard.remove();
                        showToast('Option deleted');
                        // Update header counts
                        if (variantCard) {
                            const freshHeader = await fetchFreshContent(`#${variantCard.id} .sw-variant-header`);
                            const currentHeader = variantCard.querySelector('.sw-variant-header');
                            if (freshHeader && currentHeader) currentHeader.innerHTML = freshHeader.innerHTML;
                        }
                    } else if (variantCard) {
                        await refreshVariantCard(variantCard);
                        showToast('Deleted');
                    }
                } else if (isInSidebar) {
                    await refreshSidebar();
                    showToast(method === 'DELETE' ? 'Image deleted' : 'Saved');
                } else if (isSkuForm) {
                    await refreshSkuSection();
                    showToast(method === 'DELETE' ? 'SKU deleted' : 'Saved');
                } else if (variantCard) {
                    await refreshVariantCard(variantCard);
                    showToast(method === 'PATCH' ? 'Saved' : 'Added');
                } else {
                    showToast('Saved');
                }

                // Also refresh stats in sidebar
                const freshStats = await fetchFreshContent('.sw-stats');
                const stats = document.querySelector('.sw-stats');
                if (freshStats && stats) stats.innerHTML = freshStats.innerHTML;

            } else {
                // Error response
                const text = await res.text();
                const errDoc = new DOMParser().parseFromString(text, 'text/html');
                const errMsg = errDoc.querySelector('.flash-error li')?.textContent || `Error (${res.status})`;
                showToast(errMsg, true);
            }
        } catch (err) {
            console.error('Form submit error:', err);
            showToast('Something went wrong', true);
        } finally {
            if (submitBtn && ! skipSubmitLoading) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
    });

    // Also handle variant header click-to-collapse (delegate since content gets replaced)
    workspace.addEventListener('click', (e) => {
        const header = e.target.closest('.sw-variant-header');
        if (!header) return;
        // Don't toggle if clicking inside an edit details
        if (e.target.closest('.sw-inline-edit')) return;
        header.closest('.sw-variant-card')?.classList.toggle('is-collapsed');
    });
};

const wireSwOptLabelValueSync = (root = document) => {
    root.querySelectorAll('.sw-opt-form, .sw-option-add').forEach((form) => {
        if (form.dataset.swLabelValueSync === '1') {
            return;
        }

        const labelInput = form.querySelector('input[name="label"]');
        const valueInput = form.querySelector('input[name="value"]');
        if (!labelInput || !valueInput) {
            return;
        }

        form.dataset.swLabelValueSync = '1';

        if (!valueInput.value.trim() && labelInput.value.trim()) {
            valueInput.value = labelInput.value;
        }

        let valueDirty = false;

        labelInput.addEventListener('input', () => {
            if (valueInput.dataset.swValueManual !== '1') {
                valueInput.value = labelInput.value;
            }
        });

        valueInput.addEventListener('focus', () => {
            valueInput.dataset.swValueManual = '1';
        });

        valueInput.addEventListener('input', () => {
            valueDirty = true;
        });

        valueInput.addEventListener('blur', () => {
            const label = labelInput.value.trim();
            const value = valueInput.value.trim();

            if (!value && label) {
                valueInput.value = label;
                valueDirty = false;
                delete valueInput.dataset.swValueManual;
                return;
            }

            if (!valueDirty || value === label) {
                delete valueInput.dataset.swValueManual;
            }

            valueDirty = false;
        });
    });
};

const initStyleWorkspaceAjaxV2 = () => {
    const workspace = document.querySelector('.sw-workspace');
    if (!workspace) return;

    wireSwOptLabelValueSync(workspace);

    const fragmentKeys = ['sidebar', 'publish', 'variants', 'skus'];
    const fragmentSelector = (key) => `[data-sw-fragment="${key}"]`;

    const applySwSkuFilter = (input) => {
        const section = input.closest('[data-sw-fragment="skus"]');
        const meta = section?.querySelector('[data-sw-sku-filter-meta]');
        const rows = section?.querySelectorAll('tr.sw-sku-row');
        if (!rows?.length) return;
        const q = input.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const blob = row.dataset.swSkuSearch || row.textContent;
            const show = !q || blob.toLowerCase().includes(q);
            row.hidden = !show;
            if (show) visible += 1;
        });
        if (meta) {
            meta.textContent = q ? `${visible} / ${rows.length}` : '';
        }
    };

    workspace.addEventListener('input', (event) => {
        const t = event.target;
        if (t?.matches?.('[data-sw-sku-filter]')) {
            applySwSkuFilter(t);
        }
    });

    const showToast = (message, isError = false) => {
        let toast = document.getElementById('sw-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'sw-toast';
            toast.className = 'sw-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.toggle('sw-toast-error', isError);
        toast.classList.add('sw-toast-show');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.classList.remove('sw-toast-show'), 2200);
    };

    const detailKey = (detail) => {
        if (detail.id) return `id:${detail.id}`;
        if (detail.classList.contains('sw-opt-card')) return `option:${detail.dataset.optId}`;
        const variantCard = detail.closest('.sw-variant-card');
        if (variantCard?.id && detail.classList.contains('sw-inline-edit')) return `variant-edit:${variantCard.id}`;
        const skuRow = detail.closest('.sw-sku-row');
        if (skuRow?.dataset.href && detail.classList.contains('sw-sku-edit-detail')) return `sku-edit:${skuRow.dataset.href}`;
        const form = detail.querySelector('form[action]');
        if (form) return `form:${form.action}:${detail.className}`;
        return `summary:${detail.querySelector('summary')?.textContent?.trim() || ''}:${detail.className}`;
    };

    const captureState = () => ({
        scrollY: window.scrollY,
        openDetails: new Set(Array.from(workspace.querySelectorAll('details[open]')).map(detailKey).filter(Boolean)),
        collapsedVariants: new Set(Array.from(workspace.querySelectorAll('.sw-variant-card.is-collapsed')).map((card) => card.id).filter(Boolean)),
    });

    const restoreState = (state) => {
        workspace.querySelectorAll('details').forEach((detail) => {
            if (state.openDetails.has(detailKey(detail))) {
                detail.open = true;
            }
        });
        workspace.querySelectorAll('.sw-variant-card').forEach((card) => {
            card.classList.toggle('is-collapsed', state.collapsedVariants.has(card.id));
        });
        window.scrollTo({ top: state.scrollY, left: window.scrollX, behavior: 'auto' });
    };

    const parseHtmlResponse = async (response) => {
        const contentType = response.headers.get('content-type') || '';
        const text = await response.text();
        if (!response.ok) {
            if (contentType.includes('application/json')) {
                const data = JSON.parse(text || '{}');
                throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Save failed.');
            }
            const errorDoc = new DOMParser().parseFromString(text, 'text/html');
            throw new Error(errorDoc.querySelector('.flash-error li, .invalid-feedback, .text-danger')?.textContent?.trim() || `Save failed (${response.status}).`);
        }
        return new DOMParser().parseFromString(text, 'text/html');
    };

    const replaceFragments = (doc, keys, state) => {
        keys.forEach((key) => {
            const current = document.querySelector(fragmentSelector(key));
            const fresh = doc.querySelector(fragmentSelector(key));
            if (current && fresh) {
                current.replaceWith(fresh);
            }
        });
        restoreState(state);
        initRetailMediaManagers();
        wireSwOptLabelValueSync(workspace);
    };

    const refreshFromCurrentPage = async (keys = fragmentKeys, message = null) => {
        const state = captureState();
        const response = await fetch(window.location.href, {
            cache: 'no-store',
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const doc = await parseHtmlResponse(response);
        replaceFragments(doc, keys, state);
        if (message) showToast(message);
        return true;
    };

    const fragmentsForForm = (form) => {
        if (form.closest(fragmentSelector('sidebar'))) return ['sidebar'];
        if (form.closest(fragmentSelector('publish'))) return fragmentKeys;
        if (form.closest(fragmentSelector('variants'))) return ['sidebar', 'variants', 'skus'];
        if (form.closest(fragmentSelector('skus'))) return ['sidebar', 'skus'];
        return fragmentKeys;
    };

    const submitWithoutReload = async (form) => {
        const submitButton = form.querySelector('button[type="submit"]');
        const originalLabel = submitButton?.textContent;
        const state = captureState();

        if (submitButton && !submitButton.classList.contains('sw-opt-summary-del-btn')) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }

        try {
            const cameraInput = form.querySelector('[data-rfm-camera]');
            const uploadInput = form.querySelector('[data-rfm-upload]');
            const uploadFile = selectedMediaUploadFile(cameraInput, uploadInput);
            const payload = new FormData(form);
            if (uploadFile) {
                if (submitButton && !submitButton.classList.contains('sw-opt-summary-del-btn')) {
                    submitButton.textContent = 'Preparing image...';
                }
                const preparedFile = await normalizeImageUploadFile(uploadFile);
                payload.set('uploaded_image', preparedFile, preparedFile.name || uploadFile.name);
            }
            payload.delete('uploaded_image_alt');

            const response = await fetch(form.action, {
                method: 'POST',
                body: payload,
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const doc = await parseHtmlResponse(response);
            replaceFragments(doc, fragmentsForForm(form), state);
            showToast((payload.get('_method') || form.method).toString().toUpperCase() === 'DELETE' ? 'Deleted.' : 'Saved.');
        } catch (error) {
            showToast(error.message || 'Save failed.', true);
        } finally {
            if (submitButton && !submitButton.classList.contains('sw-opt-summary-del-btn')) {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel;
            }
        }
    };

    window.LHCStyleWorkspace = {
        refresh: refreshFromCurrentPage,
        refreshAfterImageChange: () => refreshFromCurrentPage(fragmentKeys, 'Image saved.'),
        showToast,
    };

    workspace.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (event.defaultPrevented) return;
        event.preventDefault();
        submitWithoutReload(form);
    });

    workspace.addEventListener('click', (event) => {
        const addImageTrigger = event.target.closest('[data-sw-option-add-image]');
        if (addImageTrigger) {
            event.preventDefault();
            event.stopPropagation();

            const optionCard = addImageTrigger.closest('.sw-opt-card');
            if (!optionCard) return;

            optionCard.open = true;
            const manager = optionCard.querySelector('[data-rfm-media-manager]');
            const pasteTab = manager?.querySelector('[data-rfm-media-tab="paste"]');
            const pasteZone = manager?.querySelector('[data-rfm-media-paste]');

            pasteTab?.click();
            window.setTimeout(() => {
                pasteZone?.focus();
                pasteZone?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                showToast('Paste image with Ctrl+V.');
            }, 60);
            return;
        }

        const header = event.target.closest('.sw-variant-header');
        if (!header || event.target.closest('.sw-inline-edit')) return;
        header.closest('.sw-variant-card')?.classList.toggle('is-collapsed');
    });
};

const initRetailMediaManagers = () => {
    document.querySelectorAll('[data-rfm-media-manager]').forEach((manager) => {
        if (manager.dataset.rfmMediaInitialized === '1') return;
        manager.dataset.rfmMediaInitialized = '1';

        const form = manager.querySelector('[data-rfm-media-add-form]');
        if (!form) return;

        // Tabs
        const tabs = manager.querySelectorAll('[data-rfm-media-tab]');
        const panels = manager.querySelectorAll('[data-rfm-media-panel]');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.rfmMediaTab;
                tabs.forEach((t) => {
                    const on = t === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                panels.forEach((p) => {
                    p.classList.toggle('is-active', p.dataset.rfmMediaPanel === target);
                });
                if (target === 'paste') {
                    manager.querySelector('[data-rfm-media-paste]')?.focus();
                }
            });
        });

        // Camera/upload file feedback. Mobile browsers are inconsistent when a
        // visually-hidden file input is inside a label, so also trigger the
        // picker explicitly from the tapped capture card and show a preview.
        const cameraInput = form.querySelector('[data-rfm-camera]');
        const uploadInput = form.querySelector('[data-rfm-upload]');
        const filePreview = form.querySelector('[data-rfm-file-preview]');
        const filePreviewImg = form.querySelector('[data-rfm-file-preview-img]');
        const filePreviewName = form.querySelector('[data-rfm-file-preview-name]');
        const filePreviewMeta = form.querySelector('[data-rfm-file-preview-meta]');
        const fileClear = form.querySelector('[data-rfm-file-clear]');
        let previewObjectUrl = null;

        const formatBytes = (bytes) => {
            const size = Number(bytes || 0);
            if (size <= 0) return '';
            if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
            return `${(size / (1024 * 1024)).toFixed(1)} MB`;
        };

        const clearFilePreview = () => {
            if (previewObjectUrl) {
                URL.revokeObjectURL(previewObjectUrl);
                previewObjectUrl = null;
            }
            if (filePreview) filePreview.hidden = true;
            if (filePreviewImg) {
                filePreviewImg.removeAttribute('src');
                filePreviewImg.alt = '';
            }
            if (filePreviewName) filePreviewName.textContent = 'Selected photo';
            if (filePreviewMeta) filePreviewMeta.textContent = '';
        };

        const setFilePreview = (file, sourceLabel) => {
            if (!file || !filePreview) {
                clearFilePreview();
                return;
            }

            clearFilePreview();
            previewObjectUrl = URL.createObjectURL(file);
            if (filePreviewImg) {
                filePreviewImg.src = previewObjectUrl;
                filePreviewImg.alt = file.name || 'Selected product photo';
            }
            if (filePreviewName) filePreviewName.textContent = file.name || 'Selected product photo';
            if (filePreviewMeta) {
                filePreviewMeta.textContent = [sourceLabel, formatBytes(file.size)].filter(Boolean).join(' · ');
            }
            filePreview.hidden = false;
            form.dispatchEvent(new CustomEvent('rfm:file-preview-changed', {
                bubbles: true,
                detail: { src: previewObjectUrl },
            }));
        };

        const clearSelectedFiles = () => {
            if (cameraInput) cameraInput.value = '';
            if (uploadInput) uploadInput.value = '';
            clearFilePreview();
        };

        manager.querySelectorAll('.rfm-media-capture').forEach((captureCard) => {
            captureCard.addEventListener('click', (event) => {
                const input = captureCard.querySelector('input[type="file"]');
                if (!input || event.target === input) return;
                event.preventDefault();
                input.click();
            });
        });

        cameraInput?.addEventListener('change', () => {
            if (cameraInput.files?.length) {
                if (uploadInput) uploadInput.value = '';
                setFilePreview(cameraInput.files[0], 'Camera');
            } else {
                clearFilePreview();
            }
        });

        uploadInput?.addEventListener('change', () => {
            if (uploadInput.files?.length) {
                if (cameraInput) cameraInput.value = '';
                setFilePreview(uploadInput.files[0], 'Upload');
            } else {
                clearFilePreview();
            }
        });

        fileClear?.addEventListener('click', clearSelectedFiles);
        form.addEventListener('reset', clearFilePreview);
        form.addEventListener('rfm:file-preview-clear', () => {
            clearFilePreview();
            form.dispatchEvent(new CustomEvent('rfm:file-preview-changed', {
                bubbles: true,
                detail: { src: null },
            }));
        });

        // Paste handling
        const pasteZone = manager.querySelector('[data-rfm-media-paste]');
        const status = manager.querySelector('[data-rfm-media-paste-status]');
        const phoneCreate = manager.querySelector('[data-rfm-phone-create]');
        const phoneStatus = manager.querySelector('[data-rfm-phone-status]');
        const phoneUrlWrap = manager.querySelector('.rfm-phone-url');
        const phoneUrlInput = manager.querySelector('[data-rfm-phone-url]');

        const setPhoneStatus = (message, isError = false) => {
            if (!phoneStatus) return;
            phoneStatus.hidden = !message;
            phoneStatus.textContent = message || '';
            phoneStatus.classList.toggle('is-error', isError);
        };

        const pollPhoneJob = (statusUrl) => {
            if (!statusUrl) return;
            let attempts = 0;
            let finished = false;
            let interval = null;

            const removeWakeListeners = () => {
                document.removeEventListener('visibilitychange', wakeCheck);
                window.removeEventListener('focus', wakeCheck);
            };

            const finish = (callback) => {
                if (finished) return;
                finished = true;
                if (interval) window.clearInterval(interval);
                removeWakeListeners();
                callback();
            };

            const statusUrlWithCacheBuster = () => {
                const url = new URL(statusUrl, window.location.href);
                url.searchParams.set('_', String(Date.now()));
                return url.toString();
            };

            const checkStatus = async () => {
                if (finished) return;
                attempts += 1;
                if (attempts > 240) {
                    finish(() => setPhoneStatus('Phone request expired. Create a new request if needed.', true));
                    return;
                }

                try {
                    const response = await fetch(statusUrlWithCacheBuster(), {
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const data = await response.json().catch(() => ({}));
                    const job = data.job || {};
                    if (job.status === 'completed') {
                        finish(() => {
                            setPhoneStatus('Phone photo saved.');
                            if (window.LHCStyleWorkspace?.refreshAfterImageChange) {
                                window.LHCStyleWorkspace.refreshAfterImageChange().catch(() => window.location.reload());
                            } else {
                                window.setTimeout(() => window.location.reload(), 150);
                            }
                        });
                    } else if (job.status === 'cancelled') {
                        finish(() => setPhoneStatus('Phone request closed. Create a new request if needed.', true));
                    } else if (job.status === 'failed') {
                        finish(() => setPhoneStatus(job.error_message || 'Phone upload failed.', true));
                    }
                } catch (_) {
                    // Keep polling; the phone or server may be briefly busy.
                }
            };

            const wakeCheck = () => {
                if (!document.hidden) {
                    checkStatus();
                }
            };

            document.addEventListener('visibilitychange', wakeCheck);
            window.addEventListener('focus', wakeCheck);
            checkStatus();
            interval = window.setInterval(checkStatus, 1200);
        };

        phoneCreate?.addEventListener('click', async () => {
            const jobUrl = form.dataset.mobileCaptureJobUrl;
            if (!jobUrl) return;

            const payload = new FormData(form);
            payload.delete('uploaded_image');
            payload.delete('uploaded_image_alt');
            payload.delete('external_url');
            payload.delete('mirror_external');
            payload.delete('paste_upload');

            try {
                phoneCreate.disabled = true;
                setPhoneStatus('Creating phone upload request...');
                if (phoneUrlWrap) phoneUrlWrap.hidden = true;

                const response = await fetch(jobUrl, {
                    method: 'POST',
                    body: payload,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.ok) {
                    const message = data.message || Object.values(data.errors || {}).flat()[0] || 'Unable to create phone request.';
                    throw new Error(message);
                }

                if (phoneUrlInput) {
                    phoneUrlInput.value = data.phone_url || '';
                    phoneUrlWrap.hidden = Boolean(data.phone_connected);
                    if (!data.phone_connected) {
                        phoneUrlInput.select();
                        navigator.clipboard?.writeText(data.phone_url || '').catch(() => {});
                    }
                }
                setPhoneStatus(data.phone_connected
                    ? 'Request sent to the connected phone. Pick up the phone, take the photo, and send it.'
                    : 'No live phone detected. Open the phone capture page once using the link above, then future requests will appear automatically.'
                );
                pollPhoneJob(data.status_url);
            } catch (error) {
                setPhoneStatus(error.message || 'Unable to create phone request.', true);
            } finally {
                phoneCreate.disabled = false;
            }
        });

        form.addEventListener('submit', async (event) => {
            if (form.matches('[data-rfm-quick-image-form], [data-picture-preview-replace-form]')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const submitButton = form.querySelector('button[type="submit"]');
            const originalLabel = submitButton?.textContent || 'Add image';
            const cameraInput = form.querySelector('[data-rfm-camera]');
            const uploadInput = form.querySelector('[data-rfm-upload]');
            const uploadFile = selectedMediaUploadFile(cameraInput, uploadInput);
            const payload = new FormData(form);

            try {
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = uploadFile ? 'Preparing image...' : 'Saving...';
                }

                if (uploadFile) {
                    const preparedFile = await normalizeImageUploadFile(uploadFile);
                    payload.set('uploaded_image', preparedFile, preparedFile.name || uploadFile.name);
                }
                payload.delete('uploaded_image_alt');

                if (submitButton && uploadFile) {
                    submitButton.textContent = 'Uploading...';
                }

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: payload,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await jsonResponseOrText(response);
                if (!response.ok) {
                    throw new Error(uploadFailureMessage(response, data, 'Unable to add image.', form.action));
                }

                if (window.LHCStyleWorkspace?.refreshAfterImageChange) {
                    await window.LHCStyleWorkspace.refreshAfterImageChange();
                } else {
                    window.location.reload();
                }
            } catch (error) {
                window.alert(error.message || 'Unable to add image.');
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalLabel;
                }
            }
        });

        if (pasteZone) {
            const setStatus = (message, isError = false) => {
                if (!status) return;
                status.hidden = !message;
                status.textContent = message || '';
                status.classList.toggle('is-error', isError);
            };
            const setBusy = (isBusy) => {
                pasteZone.classList.toggle('is-uploading', isBusy);
                pasteZone.setAttribute('aria-busy', isBusy ? 'true' : 'false');
            };

            pasteZone.addEventListener('focus', () => pasteZone.classList.add('is-active'));
            pasteZone.addEventListener('blur', () => pasteZone.classList.remove('is-active'));
            pasteZone.addEventListener('click', () => pasteZone.focus());

            pasteZone.addEventListener('paste', async (event) => {
                const items = Array.from(event.clipboardData?.items || []);
                const imageItem = items.find((item) => item.type.startsWith('image/'));
                const file = imageItem?.getAsFile();

                if (!file) {
                    setStatus('Clipboard has no image. Copy the actual image, then paste again.', true);
                    return;
                }

                event.preventDefault();
                setBusy(true);
                setStatus('Uploading pasted image…');

                try {
                    const preparedFile = await normalizeImageUploadFile(file);
                    const payload = new FormData(form);
                    payload.set('uploaded_image', preparedFile, preparedFile.name || file.name || `pasted-${Date.now()}.jpg`);
                    payload.set('paste_upload', '1');
                    payload.delete('external_url');
                    payload.delete('uploaded_image_alt');

                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: payload,
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const data = await jsonResponseOrText(response);
                    if (!response.ok) {
                        throw new Error(uploadFailureMessage(response, data, 'Unable to upload pasted image.', form.action));
                    }
                    setStatus('Saved.');
                    if (window.LHCStyleWorkspace?.refreshAfterImageChange) {
                        await window.LHCStyleWorkspace.refreshAfterImageChange();
                    } else {
                        window.location.reload();
                    }
                } catch (error) {
                    setStatus(error.message || 'Unable to upload pasted image.', true);
                } finally {
                    setBusy(false);
                }
            });
        }
    });
};

const initRetailFamilyManager = () => {
    const root = document.querySelector('[data-rfm-root]');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        || root.querySelector('input[name="_token"]')?.value
        || '';
    const toast = root.querySelector('[data-rfm-toast]');
    const toastTimers = { hide: null };
    const showToast = (message, isError = false) => {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        toast.classList.toggle('is-error', isError);
        // force reflow so transition runs
        void toast.offsetWidth;
        toast.classList.add('is-visible');
        clearTimeout(toastTimers.hide);
        toastTimers.hide = setTimeout(() => {
            toast.classList.remove('is-visible');
            setTimeout(() => { toast.hidden = true; }, 220);
        }, 2200);
    };

    root.querySelectorAll('[data-rfm-open-target]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const selector = link.dataset.rfmOpenTarget;
            if (!selector) return;
            const target = document.querySelector(selector);
            if (!target) return;
            event.preventDefault();
            if (target.tagName === 'DETAILS') {
                target.open = true;
            }
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    root.querySelectorAll('[data-rfm-group-type-select]').forEach((select) => {
        const form = select.closest('form');
        const customField = form?.querySelector('[data-rfm-group-type-new]');
        const customInput = customField?.querySelector('input');
        const syncCustomType = () => {
            const isCustom = select.value === '__new';
            if (customField) customField.hidden = !isCustom;
            if (customInput) {
                customInput.required = isCustom;
                if (isCustom) {
                    window.setTimeout(() => customInput.focus(), 30);
                }
            }
        };
        select.addEventListener('change', syncCustomType);
        syncCustomType();
    });

    // SKU filters (multi-select)
    const getSkuItems = () => root.querySelectorAll('[data-rfm-sku]');
    const searchInput = root.querySelector('[data-rfm-search]');
    const emptyState = root.querySelector('[data-rfm-empty]');
    const filterSummary = root.querySelector('[data-rfm-filter-summary]');
    const filterBadge = root.querySelector('[data-rfm-filter-badge]');
    const filterClear = root.querySelector('[data-rfm-filter-clear]');
    const variantFilterChips = root.querySelectorAll('[data-rfm-filter-variant]');
    const statusFilterChips = root.querySelectorAll('[data-rfm-filter-status]');
    const channelFilterChips = root.querySelectorAll('[data-rfm-filter-channel]');
    const qualityFilterChips = root.querySelectorAll('[data-rfm-filter-quality]');
    const barcodeFilterChips = root.querySelectorAll('[data-rfm-filter-barcode]');
    const getTotalSkuCount = () => getSkuItems().length;
    let searchTerm = '';

    const activeVariantByAxis = new Map();
    const activeStatuses = new Set();
    const activeChannels = new Set();
    const activeBarcode = new Set();
    const activeQuality = new Set();

    const updateSkuToggleState = (skuItem, field, isOn) => {
        if (!skuItem) return;

        if (field === 'is_pos_active') skuItem.dataset.rfmNotPos = isOn ? '0' : '1';
        if (field === 'is_ecommerce_active') skuItem.dataset.rfmNotOnline = isOn ? '0' : '1';
        if (field === 'is_inventory_tracked') skuItem.dataset.rfmNotInventory = isOn ? '0' : '1';

        skuItem.querySelectorAll(`[data-rfm-row-toggle][data-rfm-field="${field}"]`).forEach((button) => {
            button.classList.toggle('rfm-dot-on', isOn);
            button.classList.toggle('is-on', isOn);
            button.setAttribute('aria-pressed', isOn ? 'true' : 'false');

            const targetName = {
                is_pos_active: 'POS',
                is_ecommerce_active: 'Ecommerce',
                is_inventory_tracked: 'inventory tracking',
            }[field] || 'setting';
            button.title = isOn ? `Turn ${targetName} off` : `Turn ${targetName} on`;
            button.setAttribute(
                'aria-label',
                button.dataset.rfmTargetLabel
                    ? `${button.title} for ${button.dataset.rfmTargetLabel}`
                    : button.title,
            );
        });

        skuItem.querySelectorAll(`[data-rfm-toggle="${field}"]`).forEach((input) => {
            input.checked = isOn;
            input.closest('.rfm-tog')?.classList.toggle('is-on', isOn);
        });
    };

    const saveProductFlag = async (action, field, isOn) => {
        return saveProductField(action, field, isOn ? '1' : '0');
    };

    const saveProductField = async (action, field, value) => {
        const formData = new FormData();
        formData.append('_token', csrf);
        formData.append('_method', 'PATCH');
        formData.append('partial', '1');
        formData.append(field, value);

        const response = await fetch(action, {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const msg = data.message || Object.values(data.errors || {}).flat()[0] || 'Save failed.';
            throw new Error(msg);
        }

        return data;
    };

    const matchesChannelFilter = (item, key) => {
        switch (key) {
            case 'pos-on': return item.dataset.rfmNotPos === '0';
            case 'pos-off': return item.dataset.rfmNotPos === '1';
            case 'web-on': return item.dataset.rfmNotOnline === '0';
            case 'web-off': return item.dataset.rfmNotOnline === '1';
            case 'stock-on': return item.dataset.rfmNotInventory === '0';
            case 'stock-off': return item.dataset.rfmNotInventory === '1';
            default: return false;
        }
    };

    const matchesBarcodeFilter = (item, key) => {
        switch (key) {
            case 'has-barcode': return item.dataset.rfmNeedsBarcode === '0';
            case 'needs-barcode': return item.dataset.rfmNeedsBarcode === '1';
            case 'duplicate-barcode': return item.dataset.rfmDuplicateBarcode === '1';
            default: return false;
        }
    };

    const matchesQualityFilter = (item, key) => {
        switch (key) {
            case 'needs-price': return item.dataset.rfmNeedsPrice === '1';
            case 'needs-image': return item.dataset.rfmNeedsImage === '1';
            case 'out-of-stock': return item.dataset.rfmOutOfStock === '1';
            default: return false;
        }
    };

    const refreshBarcodeDuplicateFlags = () => {
        const byBarcode = new Map();
        getSkuItems().forEach((item) => {
            const code = (item.dataset.rfmBarcode || '').trim().toLowerCase();
            if (!code) return;
            if (!byBarcode.has(code)) byBarcode.set(code, []);
            byBarcode.get(code).push(item);
        });
        getSkuItems().forEach((item) => {
            const code = (item.dataset.rfmBarcode || '').trim().toLowerCase();
            const count = code ? (byBarcode.get(code)?.length ?? 0) : 0;
            item.dataset.rfmDuplicateBarcode = code && count > 1 ? '1' : '0';
        });
    };

    refreshBarcodeDuplicateFlags();

    const matchesVariantFilters = (item) => {
        if (activeVariantByAxis.size === 0) return true;

        const optionIds = (item.dataset.rfmVariantOptions || '').split(/\s+/).filter(Boolean);
        for (const selected of activeVariantByAxis.values()) {
            const matchesAxis = [...selected].some((optionId) => optionIds.includes(optionId));
            if (!matchesAxis) return false;
        }

        return true;
    };

    const countActiveFilters = () => {
        let count = 0;
        activeVariantByAxis.forEach((selected) => { count += selected.size; });
        count += activeStatuses.size + activeChannels.size + activeBarcode.size + activeQuality.size;
        return count;
    };

    const updateFilterChrome = (visible) => {
        const activeCount = countActiveFilters();

        if (filterBadge) {
            filterBadge.textContent = String(activeCount);
            filterBadge.hidden = activeCount === 0;
        }

        if (filterClear) {
            filterClear.hidden = activeCount === 0;
        }

        if (filterSummary) {
            const total = getTotalSkuCount();
            if (activeCount === 0) {
                filterSummary.textContent = `All ${total} SKUs`;
            } else {
                filterSummary.textContent = `${visible} of ${total} SKUs`;
            }
        }
    };

    const applyFilters = () => {
        let visible = 0;
        getSkuItems().forEach((item) => {
            const matchesSearch = !searchTerm || (item.dataset.rfmSearch || '').includes(searchTerm);
            const matchesStatus = activeStatuses.size === 0
                || activeStatuses.has(item.dataset.rfmStatus || '');
            const matchesChannels = activeChannels.size === 0
                || [...activeChannels].some((key) => matchesChannelFilter(item, key));
            const matchesBarcode = activeBarcode.size === 0
                || [...activeBarcode].some((key) => matchesBarcodeFilter(item, key));
            const matchesQuality = activeQuality.size === 0
                || [...activeQuality].some((key) => matchesQualityFilter(item, key));
            const matchesVariants = matchesVariantFilters(item);
            const show = matchesSearch && matchesStatus && matchesChannels
                && matchesBarcode && matchesQuality && matchesVariants;
            item.hidden = !show;
            if (show) visible += 1;
        });

        root.querySelectorAll('[data-rfm-sku-group]').forEach((group) => {
            const visibleInGroup = [...group.querySelectorAll('[data-rfm-sku]')].some((item) => !item.hidden);
            group.hidden = !visibleInGroup;
        });

        if (emptyState) emptyState.hidden = visible !== 0;
        updateFilterChrome(visible);
    };

    const setChipPressed = (chip, isPressed) => {
        chip.classList.toggle('is-active', isPressed);
        chip.setAttribute('aria-pressed', isPressed ? 'true' : 'false');
    };

    const toggleVariantChip = (chip) => {
        const axisId = chip.dataset.rfmFilterAxis || '';
        const optionId = chip.dataset.rfmFilterVariant || '';
        if (!axisId || !optionId) return;

        const axisSelections = activeVariantByAxis.get(axisId) || new Set();
        const isPressed = chip.getAttribute('aria-pressed') === 'true';
        if (isPressed) {
            axisSelections.delete(optionId);
            if (axisSelections.size === 0) {
                activeVariantByAxis.delete(axisId);
            } else {
                activeVariantByAxis.set(axisId, axisSelections);
            }
            setChipPressed(chip, false);
        } else {
            axisSelections.add(optionId);
            activeVariantByAxis.set(axisId, axisSelections);
            setChipPressed(chip, true);
        }
    };

    const toggleSetChip = (chip, activeSet, valueAttr) => {
        const value = chip.getAttribute(valueAttr) || '';
        if (!value) return;

        const isPressed = chip.getAttribute('aria-pressed') === 'true';
        if (isPressed) {
            activeSet.delete(value);
            setChipPressed(chip, false);
        } else {
            activeSet.add(value);
            setChipPressed(chip, true);
        }
    };

    const clearAllFilters = () => {
        activeVariantByAxis.clear();
        activeStatuses.clear();
        activeChannels.clear();
        activeBarcode.clear();
        activeQuality.clear();
        root.querySelectorAll('.rfm-filter-chip[aria-pressed="true"]').forEach((chip) => {
            setChipPressed(chip, false);
        });
        applyFilters();
    };

    variantFilterChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            toggleVariantChip(chip);
            applyFilters();
        });
    });

    statusFilterChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            toggleSetChip(chip, activeStatuses, 'data-rfm-filter-status');
            applyFilters();
        });
    });

    channelFilterChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            toggleSetChip(chip, activeChannels, 'data-rfm-filter-channel');
            applyFilters();
        });
    });

    barcodeFilterChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            toggleSetChip(chip, activeBarcode, 'data-rfm-filter-barcode');
            applyFilters();
        });
    });

    qualityFilterChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            toggleSetChip(chip, activeQuality, 'data-rfm-filter-quality');
            applyFilters();
        });
    });

    if (filterClear) {
        filterClear.addEventListener('click', clearAllFilters);
    }

    const getSkuGroupDetails = () => [...root.querySelectorAll('[data-rfm-sku-group]')].filter((el) => !el.hidden);
    const expandAllSkuGroupsBtn = root.querySelector('[data-rfm-sku-groups-expand-all]');
    const collapseAllSkuGroupsBtn = root.querySelector('[data-rfm-sku-groups-collapse-all]');

    expandAllSkuGroupsBtn?.addEventListener('click', () => {
        getSkuGroupDetails().forEach((group) => {
            group.open = true;
        });
    });

    collapseAllSkuGroupsBtn?.addEventListener('click', () => {
        getSkuGroupDetails().forEach((group) => {
            group.open = false;
        });
    });

    if (searchInput) {
        let searchTimer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                searchTerm = searchInput.value.trim().toLowerCase();
                applyFilters();
            }, 100);
        });
    }

    root.querySelectorAll('.rfm-shared-field').forEach((fieldWrap) => {
        const apply = fieldWrap.querySelector('.rfm-shared-apply input[type="checkbox"]');
        if (!apply) return;

        fieldWrap.querySelectorAll('input:not([type="checkbox"]), select, textarea').forEach((field) => {
            const markApply = () => { apply.checked = true; };
            field.addEventListener('input', markApply);
            field.addEventListener('change', markApply);
        });
    });

    root.querySelectorAll('[data-rfm-variant-pricing]').forEach((pricing) => {
        const form = pricing.querySelector('form');
        const countLabel = pricing.querySelector('[data-rfm-variant-price-count]');
        const submit = pricing.querySelector('[data-rfm-variant-price-submit]');
        const axes = pricing.querySelectorAll('[data-rfm-variant-pricing-axis]');

        const filtersByGroup = () => {
            const filters = new Map();
            axes.forEach((axis) => {
                const groupId = axis.dataset.groupId;
                const checked = Array.from(axis.querySelectorAll('[data-rfm-variant-option-check]:checked'))
                    .map((input) => input.value)
                    .filter(Boolean);
                if (groupId && checked.length > 0) {
                    filters.set(groupId, checked);
                }
            });
            return filters;
        };

        const skuMatchesFilters = (item, filters) => {
            let byGroup = {};
            try {
                byGroup = JSON.parse(item.dataset.rfmVariantOptionsByGroup || '{}');
            } catch {
                byGroup = {};
            }
            for (const [groupId, selectedIds] of filters) {
                const productOptionId = String(byGroup[groupId] ?? '');
                if (!selectedIds.includes(productOptionId)) {
                    return false;
                }
            }
            return true;
        };

        const hasPriceFieldToApply = () => {
            if (!form) return false;
            return [
                ['apply_retail_price', 'retail_price'],
                ['apply_cost_price', 'cost_price'],
                ['apply_vat_rate', 'vat_rate'],
            ].some(([applyName, fieldName]) => {
                const apply = form.querySelector(`[name="${applyName}"]`);
                if (!apply?.checked) return false;
                const field = form.querySelector(`[name="${fieldName}"]`);
                return field && String(field.value || '').trim() !== '';
            });
        };

        const updateVariantPriceCount = () => {
            const filters = filtersByGroup();

            if (filters.size === 0) {
                if (countLabel) {
                    countLabel.textContent = 'Tick one or more values, or press All on an axis (e.g. every Colour).';
                }
                if (submit) submit.disabled = true;
                return;
            }

            const matchCount = Array.from(getSkuItems()).filter((item) => skuMatchesFilters(item, filters)).length;
            const filterParts = [];
            axes.forEach((axis) => {
                const groupName = axis.dataset.groupName || 'Variant';
                const checked = axis.querySelectorAll('[data-rfm-variant-option-check]:checked').length;
                const total = axis.querySelectorAll('[data-rfm-variant-option-check]').length;
                if (checked === 0) {
                    filterParts.push(`any ${groupName}`);
                } else if (checked === total) {
                    filterParts.push(`all ${groupName}`);
                } else {
                    filterParts.push(`${checked} ${groupName}`);
                }
            });

            if (countLabel) {
                const filterSummary = filterParts.join(' · ');
                const matchLabel = matchCount === 1 ? '1 SKU' : `${matchCount} SKUs`;
                const priceHint = hasPriceFieldToApply() ? '' : ' Tick Apply and enter at least one price.';
                countLabel.textContent = `${matchLabel} match (${filterSummary}).${priceHint}`;
            }

            if (submit) {
                submit.disabled = matchCount === 0 || !hasPriceFieldToApply();
            }
        };

        axes.forEach((axis) => {
            axis.querySelectorAll('[data-rfm-variant-option-check]').forEach((input) => {
                input.addEventListener('change', updateVariantPriceCount);
            });
            axis.querySelector('[data-rfm-variant-pricing-select-all]')?.addEventListener('click', () => {
                axis.querySelectorAll('[data-rfm-variant-option-check]').forEach((input) => {
                    input.checked = true;
                });
                updateVariantPriceCount();
            });
            axis.querySelector('[data-rfm-variant-pricing-clear-axis]')?.addEventListener('click', () => {
                axis.querySelectorAll('[data-rfm-variant-option-check]').forEach((input) => {
                    input.checked = false;
                });
                updateVariantPriceCount();
            });
        });

        form?.querySelectorAll('[name^="apply_"], [name="retail_price"], [name="cost_price"], [name="vat_rate"]').forEach((field) => {
            field.addEventListener('input', updateVariantPriceCount);
            field.addEventListener('change', updateVariantPriceCount);
        });

        updateVariantPriceCount();
    });

    const aiNamingGenerate = root.querySelector('[data-rfm-ai-naming-generate]');
    const aiNamingViewAll = root.querySelector('[data-rfm-ai-naming-view-all]');
    const aiNamingPageStatus = root.querySelector('[data-rfm-ai-naming-page-status]');
    const aiNamingReview = root.querySelector('[data-rfm-ai-naming-review]');
    const aiNamingSummary = root.querySelector('[data-rfm-ai-naming-summary]');
    const aiNamingForm = root.querySelector('[data-rfm-ai-naming-form]');
    const aiNamingStatus = root.querySelector('[data-rfm-ai-naming-status]');
    const aiNamingRows = root.querySelector('[data-rfm-ai-naming-rows]');
    const aiNamingTable = root.querySelector('[data-rfm-ai-naming-table]');
    const aiNamingEmpty = root.querySelector('[data-rfm-ai-naming-empty]');
    const aiNamingApply = root.querySelector('[data-rfm-ai-naming-apply]');
    const aiNamingApplyAll = root.querySelector('[data-rfm-ai-naming-apply-all]');
    const aiNamingSelectAll = root.querySelector('[data-rfm-ai-naming-select-all]');
    const aiNamingClear = root.querySelector('[data-rfm-ai-naming-clear]');
    let aiNamingSuggestions = new Map();

    const setAiNamingStatus = (message, isError = false) => {
        if (aiNamingStatus) {
            aiNamingStatus.textContent = message;
            aiNamingStatus.classList.toggle('is-error', isError);
        }
        if (aiNamingPageStatus) {
            aiNamingPageStatus.textContent = message;
            aiNamingPageStatus.classList.toggle('is-error', isError);
        }
    };

    const selectedAiNamingRows = () => Array.from(aiNamingRows?.querySelectorAll('[data-rfm-ai-naming-row]') || [])
        .filter((row) => row.querySelector('[data-rfm-ai-naming-use]')?.checked);

    const updateAiNamingActions = () => {
        const rowCount = aiNamingRows?.querySelectorAll('[data-rfm-ai-naming-row]').length || 0;
        const selectedCount = selectedAiNamingRows().length;
        if (aiNamingApply) aiNamingApply.disabled = selectedCount === 0;
        if (aiNamingApplyAll) aiNamingApplyAll.disabled = rowCount === 0;
        if (aiNamingSelectAll) aiNamingSelectAll.disabled = rowCount === 0;
        if (aiNamingClear) aiNamingClear.disabled = rowCount === 0;
    };

    const skuItemForProduct = (productId) => Array.from(getSkuItems())
        .find((item) => item.dataset.rfmProductId === String(productId));

    const createAiNamingInput = (name, value, maxLength, label) => {
        const wrap = document.createElement('label');
        wrap.className = `rfm-ai-naming-cell rfm-ai-naming-cell-${name.replace(/_/g, '-')}`;
        const labelSpan = document.createElement('span');
        labelSpan.className = 'rfm-ai-naming-cell-label';
        labelSpan.textContent = label || '';
        const input = document.createElement('input');
        input.type = 'text';
        input.value = value || '';
        input.maxLength = maxLength;
        input.dataset.rfmAiNamingField = name;
        input.addEventListener('input', updateAiNamingActions);
        wrap.append(labelSpan, input);
        return wrap;
    };

    const namingValuesFromSuggestion = (suggestion) => ({
        receipt_name: suggestion?.suggested?.receipt_name || '',
        inventory_name: suggestion?.suggested?.inventory_name || '',
        ecommerce_title: suggestion?.suggested?.ecommerce_title || '',
    });

    const setSkuNamingFields = (skuItem, values) => {
        if (!skuItem) return;
        Object.entries(values).forEach(([field, value]) => {
            const input = skuItem.querySelector(`[name="${field}"]`);
            if (input) {
                input.value = value || '';
                input.classList.add('rfm-ai-filled');
            }
        });
    };

    const valuesFromInlineSuggestion = (inline) => {
        const values = {};
        inline?.querySelectorAll('[data-rfm-ai-inline-field]').forEach((field) => {
            values[field.dataset.rfmAiInlineField] = field.value || '';
        });
        return values;
    };

    const applyNamingSuggestions = async (suggestions) => {
        if (!aiNamingGenerate || suggestions.length === 0) return;

        const payload = new FormData();
        payload.append('_token', csrf);
        payload.append('_method', 'PATCH');
        suggestions.forEach((suggestion, index) => {
            payload.append(`suggestions[${index}][product_id]`, suggestion.product_id || '');
            payload.append(`suggestions[${index}][receipt_name]`, suggestion.receipt_name || '');
            payload.append(`suggestions[${index}][inventory_name]`, suggestion.inventory_name || '');
            payload.append(`suggestions[${index}][ecommerce_title]`, suggestion.ecommerce_title || '');
        });

        const response = await fetch(aiNamingGenerate.dataset.rfmAiNamingApplyUrl || '', {
            method: 'POST',
            body: payload,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = data.message || Object.values(data.errors || {}).flat()[0] || 'Unable to apply naming suggestions.';
            throw new Error(message);
        }

        return data;
    };

    const renderInlineNamingSuggestion = (suggestion) => {
        const skuItem = skuItemForProduct(suggestion.product_id);
        const inline = skuItem?.querySelector('[data-rfm-ai-inline]');
        if (!inline) return;

        const values = namingValuesFromSuggestion(suggestion);
        inline.hidden = false;
        inline.dataset.productId = String(suggestion.product_id || '');
        inline.querySelector('[data-rfm-ai-inline-title]').textContent = 'Suggestion ready';
        inline.querySelector('[data-rfm-ai-inline-reason]').textContent = suggestion.reason || '';
        const confidence = inline.querySelector('[data-rfm-ai-inline-confidence]');
        if (confidence) {
            const grade = (suggestion.confidence || 'D').toLowerCase();
            confidence.className = `rfm-ai-confidence rfm-ai-confidence-${grade}`;
            confidence.textContent = suggestion.confidence || 'D';
        }
        Object.entries(values).forEach(([field, value]) => {
            const input = inline.querySelector(`[data-rfm-ai-inline-field="${field}"]`);
            if (input) input.value = value;
        });
    };

    const renderAiNamingSuggestions = (suggestions) => {
        if (!aiNamingRows || !aiNamingTable || !aiNamingEmpty) return;
        aiNamingRows.innerHTML = '';
        aiNamingSuggestions = new Map();

        suggestions.forEach((suggestion) => {
            aiNamingSuggestions.set(String(suggestion.product_id), suggestion);
            renderInlineNamingSuggestion(suggestion);

            const row = document.createElement('div');
            row.className = 'rfm-ai-naming-row';
            row.dataset.rfmAiNamingRow = '1';
            row.dataset.productId = String(suggestion.product_id || '');

            const useCell = document.createElement('label');
            useCell.className = 'rfm-ai-naming-use';
            const useCheckbox = document.createElement('input');
            useCheckbox.type = 'checkbox';
            useCheckbox.checked = true;
            useCheckbox.dataset.rfmAiNamingUse = '1';
            useCheckbox.addEventListener('change', updateAiNamingActions);
            useCell.appendChild(useCheckbox);

            const productCell = document.createElement('div');
            productCell.className = 'rfm-ai-naming-product';
            const productName = document.createElement('strong');
            productName.textContent = suggestion.current?.product_name || suggestion.current?.inventory_name || `Product ${suggestion.product_id}`;
            const reason = document.createElement('small');
            reason.textContent = suggestion.reason || '';
            productCell.append(productName, reason);

            const receiptInput = createAiNamingInput('receipt_name', suggestion.suggested?.receipt_name, 35, 'Receipt');
            const inventoryInput = createAiNamingInput('inventory_name', suggestion.suggested?.inventory_name, 80, 'POS / inventory');
            const ecommerceInput = createAiNamingInput('ecommerce_title', suggestion.suggested?.ecommerce_title, 150, 'Ecommerce');

            const confidence = document.createElement('span');
            confidence.className = `rfm-ai-confidence rfm-ai-confidence-${(suggestion.confidence || 'D').toLowerCase()}`;
            confidence.textContent = suggestion.confidence || 'D';

            row.append(useCell, productCell, receiptInput, inventoryInput, ecommerceInput, confidence);
            aiNamingRows.appendChild(row);
        });

        const hasRows = suggestions.length > 0;
        aiNamingTable.hidden = !hasRows;
        aiNamingEmpty.hidden = hasRows;
        if (aiNamingReview) aiNamingReview.hidden = !hasRows;
        if (aiNamingViewAll) aiNamingViewAll.disabled = !hasRows;
        if (aiNamingSummary) {
            aiNamingSummary.textContent = hasRows
                ? `${suggestions.length} suggestions ready`
                : 'No suggestions yet';
        }
        updateAiNamingActions();
    };

    aiNamingGenerate?.addEventListener('click', async () => {
        if (!aiNamingGenerate) return;

        setAiNamingStatus('Generating in background...');
        if (aiNamingEmpty) {
            aiNamingEmpty.hidden = false;
            aiNamingEmpty.textContent = 'Generating suggestions...';
        }
        if (aiNamingTable) aiNamingTable.hidden = true;
        if (aiNamingRows) aiNamingRows.innerHTML = '';
        if (aiNamingReview) aiNamingReview.hidden = true;
        if (aiNamingViewAll) aiNamingViewAll.disabled = true;
        updateAiNamingActions();

        aiNamingGenerate.disabled = true;
        const originalText = aiNamingGenerate.textContent;
        aiNamingGenerate.textContent = 'Generating...';
        try {
            const payload = new FormData();
            payload.append('_token', csrf);
            const response = await fetch(aiNamingGenerate.dataset.rfmAiNamingUrl || '', {
                method: 'POST',
                body: payload,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = data.message || Object.values(data.errors || {}).flat()[0] || 'Unable to generate naming suggestions.';
                throw new Error(message);
            }

            const suggestions = data.result?.suggestions || [];
            renderAiNamingSuggestions(suggestions);
            const warnings = data.result?.warnings || [];
            const model = data.result?.model || 'OpenAI naming model';
            setAiNamingStatus(warnings.length
                ? `${suggestions.length} suggestions ready via ${model}. ${warnings[0]}`
                : `${suggestions.length} naming suggestions ready via ${model}. Review, edit, then apply selected rows.`
            );
            showToast('AI naming suggestions ready.');
        } catch (error) {
            setAiNamingStatus(error.message || 'Unable to generate naming suggestions.', true);
            if (aiNamingEmpty) {
                aiNamingEmpty.hidden = false;
                aiNamingEmpty.textContent = error.message || 'Unable to generate naming suggestions.';
            }
            showToast(error.message || 'Unable to generate naming suggestions.', true);
        } finally {
            aiNamingGenerate.disabled = false;
            aiNamingGenerate.textContent = originalText;
            updateAiNamingActions();
        }
    });

    aiNamingViewAll?.addEventListener('click', () => {
        if (!aiNamingReview || aiNamingReview.hidden) return;
        aiNamingReview.open = true;
        aiNamingReview.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    aiNamingSelectAll?.addEventListener('click', () => {
        aiNamingRows?.querySelectorAll('[data-rfm-ai-naming-use]').forEach((checkbox) => {
            checkbox.checked = true;
        });
        updateAiNamingActions();
    });

    aiNamingClear?.addEventListener('click', () => {
        aiNamingRows?.querySelectorAll('[data-rfm-ai-naming-use]').forEach((checkbox) => {
            checkbox.checked = false;
        });
        updateAiNamingActions();
    });

    aiNamingApplyAll?.addEventListener('click', async () => {
        const rows = Array.from(aiNamingRows?.querySelectorAll('[data-rfm-ai-naming-row]') || []);
        if (rows.length === 0) return;

        const suggestions = rows.map((row) => {
            const suggestion = { product_id: row.dataset.productId || '' };
            row.querySelectorAll('[data-rfm-ai-naming-field]').forEach((field) => {
                suggestion[field.dataset.rfmAiNamingField] = field.value || '';
            });
            return suggestion;
        });

        aiNamingApplyAll.disabled = true;
        setAiNamingStatus('Applying all names...');
        try {
            const data = await applyNamingSuggestions(suggestions);
            setAiNamingStatus(`Applied ${data.updated_count || suggestions.length} product name updates.`);
            showToast('AI naming applied.');
            window.setTimeout(() => window.location.reload(), 450);
        } catch (error) {
            setAiNamingStatus(error.message || 'Unable to apply all naming suggestions.', true);
            showToast(error.message || 'Unable to apply all naming suggestions.', true);
            updateAiNamingActions();
        }
    });

    aiNamingForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const selectedRows = selectedAiNamingRows();
        if (selectedRows.length === 0) {
            setAiNamingStatus('Select at least one suggestion to apply.', true);
            return;
        }

        const suggestions = selectedRows.map((row) => {
            const suggestion = { product_id: row.dataset.productId || '' };
            row.querySelectorAll('[data-rfm-ai-naming-field]').forEach((field) => {
                suggestion[field.dataset.rfmAiNamingField] = field.value || '';
            });
            return suggestion;
        });

        if (aiNamingApply) aiNamingApply.disabled = true;
        setAiNamingStatus('Applying selected names...');

        try {
            const data = await applyNamingSuggestions(suggestions);
            setAiNamingStatus(`Applied ${data.updated_count || selectedRows.length} product name updates.`);
            showToast('AI naming applied.');
            window.setTimeout(() => window.location.reload(), 450);
        } catch (error) {
            setAiNamingStatus(error.message || 'Unable to apply naming suggestions.', true);
            showToast(error.message || 'Unable to apply naming suggestions.', true);
            updateAiNamingActions();
        }
    });

    root.addEventListener('click', async (event) => {
        const useButton = event.target.closest('[data-rfm-ai-inline-use]');
        const applyButton = event.target.closest('[data-rfm-ai-inline-apply]');
        const dismissButton = event.target.closest('[data-rfm-ai-inline-dismiss]');
        if (!useButton && !applyButton && !dismissButton) return;

        event.preventDefault();
        const inline = event.target.closest('[data-rfm-ai-inline]');
        const skuItem = inline?.closest('[data-rfm-sku]');
        if (!inline || !skuItem) return;

        if (dismissButton) {
            inline.hidden = true;
            return;
        }

        const values = valuesFromInlineSuggestion(inline);
        setSkuNamingFields(skuItem, values);

        if (useButton) {
            showToast('AI names copied into this SKU. Save when ready.');
            return;
        }

        if (applyButton) {
            applyButton.disabled = true;
            try {
                await applyNamingSuggestions([{ product_id: inline.dataset.productId, ...values }]);
                showToast('AI names applied.');
                window.setTimeout(() => window.location.reload(), 350);
            } catch (error) {
                showToast(error.message || 'Unable to apply naming suggestion.', true);
            } finally {
                applyButton.disabled = false;
            }
        }
    });

    const quickImageModal = root.querySelector('[data-rfm-quick-image-modal]');
    const quickImageForm = root.querySelector('[data-rfm-quick-image-form]');
    const quickImageTitle = root.querySelector('[data-rfm-quick-image-title]');
    const quickImageCurrentImg = root.querySelector('[data-rfm-quick-image-current-img]');
    const quickImagePreviewEmpty = root.querySelector('[data-rfm-quick-image-preview-empty]');
    const quickImageNextPreview = root.querySelector('[data-rfm-quick-image-preview-next]');
    const quickImageNextImg = root.querySelector('[data-rfm-quick-image-next-img]');
    const quickImageDeleteBtn = root.querySelector('[data-rfm-quick-image-delete]');
    const quickImageMobileTargetType = root.querySelector('[data-rfm-quick-image-mobile-target-type]');
    const quickImageMobileTarget = root.querySelector('[data-rfm-quick-image-mobile-target]');
    const quickImageUsageHidden = root.querySelector('[data-rfm-quick-image-usage]');
    const quickImageUsagePicker = root.querySelector('[data-rfm-quick-image-usage-picker]');
    const quickImageStatus = root.querySelector('[data-rfm-quick-image-status]');
    const quickImageRoleSelect = root.querySelector('[data-rfm-quick-image-role-select]');
    const quickImageRolePicker = root.querySelector('[data-rfm-quick-image-role-picker]');
    const quickImageRoleChips = root.querySelectorAll('[data-rfm-quick-image-role-chip]');
    const quickImageWorkflowSteps = root.querySelectorAll('[data-rfm-quick-image-workflow-step]');
    const quickImageSaved = root.querySelector('[data-rfm-quick-image-saved]');
    const quickImageSavedTitle = root.querySelector('[data-rfm-quick-image-saved-title]');
    const quickImageSavedCount = root.querySelector('[data-rfm-quick-image-saved-count]');
    const quickImageSavedGrid = root.querySelector('[data-rfm-quick-image-saved-grid]');
    const quickImageSavedEmpty = root.querySelector('[data-rfm-quick-image-saved-empty]');
    const quickImageViewOpenCurrent = quickImageModal?.querySelector('[data-rfm-quick-image-view-open="current"]');
    const quickImagePreviewEmptyWrap = quickImageModal?.querySelector('[data-rfm-quick-image-preview-empty-wrap]');
    const quickImageViewer = root.querySelector('[data-rfm-quick-image-viewer]');
    const quickImageViewerImg = root.querySelector('[data-rfm-quick-image-viewer-img]');
    const quickImageViewerCaption = root.querySelector('[data-rfm-quick-image-viewer-caption]');
    const quickImageViewerCounter = root.querySelector('[data-rfm-quick-image-viewer-counter]');
    const quickImageViewerPrev = root.querySelector('[data-rfm-quick-image-viewer-prev]');
    const quickImageViewerNext = root.querySelector('[data-rfm-quick-image-viewer-next]');
    let quickImageViewerSlides = [];
    let quickImageViewerIndex = 0;

    const QUICK_IMAGE_AUTO_SEQUENCE = {
        main: 'variant',
        variant: 'gallery',
        gallery: 'gallery',
    };

    let quickImageTrigger = null;
    let quickImageDeleteUrl = '';
    let quickImageTargets = {};
    const quickImageCompletedRoles = new Set();

    const setQuickImageStatus = (message, isError = false) => {
        if (!quickImageStatus) return;
        quickImageStatus.hidden = !message;
        quickImageStatus.textContent = message || '';
        quickImageStatus.classList.toggle('is-error', isError);
    };

    const setQuickImageNextPreview = (src) => {
        if (!quickImageNextPreview || !quickImageNextImg) return;
        if (!src) {
            quickImageNextPreview.hidden = true;
            quickImageNextImg.removeAttribute('src');
            return;
        }
        quickImageNextImg.src = src;
        quickImageNextPreview.hidden = false;
    };

    const updateQuickImageCurrentPreview = (url) => {
        const hasImage = Boolean(url);
        if (quickImageCurrentImg) {
            if (hasImage) {
                quickImageCurrentImg.src = url;
            } else {
                quickImageCurrentImg.removeAttribute('src');
            }
        }
        if (quickImageViewOpenCurrent) {
            quickImageViewOpenCurrent.hidden = !hasImage;
        }
        if (quickImagePreviewEmptyWrap) {
            quickImagePreviewEmptyWrap.hidden = hasImage;
        }
        if (quickImagePreviewEmpty) {
            quickImagePreviewEmpty.hidden = hasImage;
        }
        if (quickImageDeleteBtn) {
            quickImageDeleteBtn.hidden = !hasImage || !quickImageDeleteUrl;
        }
    };

    const buildQuickImageViewerSlides = () => {
        const slides = [];
        const currentUrl = quickImageCurrentImg?.getAttribute('src') || '';
        if (currentUrl && quickImageViewOpenCurrent && !quickImageViewOpenCurrent.hidden) {
            slides.push({ url: currentUrl, label: 'Current image' });
        }
        if (quickImageNextPreview && !quickImageNextPreview.hidden && quickImageNextImg?.src) {
            slides.push({ url: quickImageNextImg.src, label: 'New photo (not saved yet)' });
        }
        return slides;
    };

    const openQuickImageSlides = (slides, startIndex = 0) => {
        if (!slides.length || !quickImageViewer) return;
        quickImageViewerSlides = slides;
        quickImageViewerIndex = Math.max(0, Math.min(startIndex, quickImageViewerSlides.length - 1));
        renderQuickImageViewer();
        quickImageViewer.hidden = false;
        quickImageViewer.setAttribute('aria-hidden', 'false');
    };

    const renderQuickImageViewer = () => {
        const slide = quickImageViewerSlides[quickImageViewerIndex];
        if (!slide || !quickImageViewerImg) return;
        quickImageViewerImg.src = slide.url;
        quickImageViewerImg.alt = slide.label;
        if (quickImageViewerCaption) {
            const title = quickImageTitle?.textContent?.trim() || 'Product image';
            quickImageViewerCaption.textContent = `${title} — ${slide.label}`;
        }
        const hasMultiple = quickImageViewerSlides.length > 1;
        if (quickImageViewerCounter) {
            quickImageViewerCounter.hidden = !hasMultiple;
            quickImageViewerCounter.textContent = `${quickImageViewerIndex + 1} / ${quickImageViewerSlides.length}`;
        }
        if (quickImageViewerPrev) quickImageViewerPrev.hidden = !hasMultiple;
        if (quickImageViewerNext) quickImageViewerNext.hidden = !hasMultiple;
    };

    const closeQuickImageViewer = () => {
        if (!quickImageViewer) return;
        quickImageViewer.hidden = true;
        quickImageViewer.setAttribute('aria-hidden', 'true');
        if (quickImageViewerImg) quickImageViewerImg.removeAttribute('src');
    };

    const openQuickImageViewer = (startKey = 'current') => {
        quickImageViewerSlides = buildQuickImageViewerSlides();
        if (!quickImageViewerSlides.length || !quickImageViewer) return;
        const startIndex = Math.max(0, quickImageViewerSlides.findIndex((slide) => (
            startKey === 'next'
                ? slide.label.includes('New photo')
                : !slide.label.includes('New photo')
        )));
        quickImageViewerIndex = startIndex === -1 ? 0 : startIndex;
        renderQuickImageViewer();
        quickImageViewer.hidden = false;
        quickImageViewer.setAttribute('aria-hidden', 'false');
    };

    const stepQuickImageViewer = (delta) => {
        if (quickImageViewerSlides.length <= 1) return;
        quickImageViewerIndex = (quickImageViewerIndex + delta + quickImageViewerSlides.length)
            % quickImageViewerSlides.length;
        renderQuickImageViewer();
    };

    const updateQuickImageRowThumb = (trigger, url, mediaId, deleteUrl) => {
        if (!trigger || !url) return;
        trigger.dataset.currentImageUrl = url;
        if (mediaId) trigger.dataset.mediaId = String(mediaId);
        if (deleteUrl) trigger.dataset.imageDeleteUrl = deleteUrl;
        let img = trigger.querySelector('img');
        if (!img) {
            img = document.createElement('img');
            img.alt = '';
            img.loading = 'lazy';
            trigger.textContent = '';
            trigger.appendChild(img);
        }
        img.src = url;
        const skuItem = trigger.closest('[data-rfm-sku]');
        if (skuItem) skuItem.dataset.rfmNeedsImage = '0';
    };

    const safeParseQuickImageTargets = (value) => {
        if (!value) return {};
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_) {
            return {};
        }
    };

    const quickImageTargetForRole = (role) => {
        const target = quickImageTargets?.[role] || {};
        return {
            storeUrl: target.storeUrl || quickImageTrigger?.dataset.imageStoreUrl || '#',
            mobileTargetType: target.mobileTargetType || 'retail_product',
            mobileTargetId: target.mobileTargetId || quickImageTrigger?.dataset.mobileTargetId || '',
            currentUrl: target.currentUrl || '',
            mediaId: target.mediaId || '',
            deleteUrl: target.deleteUrl || '',
            count: Number.parseInt(target.count || '0', 10) || 0,
            images: Array.isArray(target.images) ? target.images : [],
        };
    };

    const persistQuickImageTargetsToTrigger = () => {
        if (!quickImageTrigger) return;
        quickImageTrigger.dataset.rfmQuickImageTargets = JSON.stringify(quickImageTargets || {});
    };

    const roleShouldReplaceSingleImage = (role) => ['main', 'variant'].includes(role);

    const roleDisplayName = (role) => {
        if (role === 'main') return 'main';
        if (role === 'variant') return 'variant';
        if (role === 'gallery') return 'gallery';
        return role || 'saved';
    };

    const renderQuickImageSavedPhotos = (role) => {
        if (!quickImageSaved || !quickImageSavedGrid) return;
        const target = quickImageTargetForRole(role);
        const images = target.images.filter((image) => image?.url);
        const title = `${roleDisplayName(role)} photos`;

        quickImageSaved.hidden = false;
        if (quickImageSavedTitle) quickImageSavedTitle.textContent = `Saved ${title}`;
        if (quickImageSavedCount) quickImageSavedCount.textContent = String(images.length);
        if (quickImageSavedEmpty) {
            quickImageSavedEmpty.hidden = images.length > 0;
            quickImageSavedEmpty.textContent = `No saved ${roleDisplayName(role)} photos yet.`;
        }

        quickImageSavedGrid.innerHTML = '';
        images.forEach((image, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rfm-quick-image-saved-card';
            button.setAttribute('aria-label', `View saved ${roleDisplayName(role)} photo ${index + 1}`);

            const img = document.createElement('img');
            img.src = image.url;
            img.alt = image.label || `Saved ${roleDisplayName(role)} photo`;
            img.loading = 'lazy';

            const badge = document.createElement('span');
            badge.textContent = role === 'gallery' ? `Gallery ${index + 1}` : roleDisplayName(role);

            button.appendChild(img);
            button.appendChild(badge);
            button.addEventListener('click', () => {
                openQuickImageSlides(images.map((item, slideIndex) => ({
                    url: item.url,
                    label: item.label || `${roleDisplayName(role)} photo ${slideIndex + 1}`,
                })), index);
            });

            quickImageSavedGrid.appendChild(button);
        });
    };

    const syncQuickImageTarget = (role) => {
        if (!quickImageForm) return;
        const target = quickImageTargetForRole(role);
        quickImageForm.action = target.storeUrl;
        if (quickImageMobileTargetType) quickImageMobileTargetType.value = target.mobileTargetType;
        if (quickImageMobileTarget) quickImageMobileTarget.value = target.mobileTargetId;

        quickImageDeleteUrl = roleShouldReplaceSingleImage(role) ? target.deleteUrl : '';
        if (quickImagePreviewEmpty) {
            quickImagePreviewEmpty.textContent = role === 'gallery'
                ? 'Gallery photos are added as extra images.'
                : `No ${role === 'variant' ? 'variant' : 'main'} photo yet`;
        }
        updateQuickImageCurrentPreview(roleShouldReplaceSingleImage(role) ? target.currentUrl : '');
        renderQuickImageSavedPhotos(role);

        const isPrimary = quickImageForm.querySelector('[data-rfm-quick-image-primary]');
        if (isPrimary) {
            isPrimary.checked = roleShouldReplaceSingleImage(role);
        }

        const submitButton = quickImageForm.querySelector('[data-rfm-quick-image-submit]');
        if (submitButton) {
            submitButton.textContent = role === 'gallery'
                ? `Add gallery photo${target.count > 0 ? ` (${target.count} saved)` : ''}`
                : `Save ${role === 'variant' ? 'variant' : 'main'} photo`;
        }
    };

    const syncQuickImageRoleUi = (role) => {
        if (!role || !quickImageRoleSelect) return;
        quickImageRoleSelect.value = role;
        if (quickImageRolePicker) quickImageRolePicker.value = role;
        quickImageRoleChips.forEach((chip) => {
            chip.classList.toggle('is-active', chip.dataset.rfmQuickImageRoleChip === role);
            chip.setAttribute('aria-pressed', chip.dataset.rfmQuickImageRoleChip === role ? 'true' : 'false');
        });
        quickImageWorkflowSteps.forEach((step) => {
            const stepRole = step.dataset.rfmQuickImageWorkflowStep || '';
            step.classList.toggle('is-active', stepRole === role);
            step.classList.toggle('is-done', quickImageCompletedRoles.has(stepRole));
        });
    };

    const setQuickImageRole = (role) => {
        if (!role) return;
        syncQuickImageRoleUi(role);
        syncQuickImageTarget(role);
    };

    const resetQuickImageInputsAfterSave = () => {
        if (!quickImageForm) return;
        quickImageForm.querySelectorAll('input[type="file"]').forEach((input) => {
            input.value = '';
        });
        quickImageForm.dispatchEvent(new CustomEvent('rfm:file-preview-clear', { bubbles: true }));
        setQuickImageNextPreview(null);
        const urlInput = quickImageForm.querySelector('input[name="external_url"]');
        if (urlInput) urlInput.value = '';
        const sourceLabel = quickImageForm.querySelector('input[name="source_label"]');
        if (sourceLabel) sourceLabel.value = '';
        const notes = quickImageForm.querySelector('input[name="notes"]');
        if (notes) notes.value = '';
        const isPrimary = quickImageForm.querySelector('[data-rfm-quick-image-primary]');
        if (isPrimary) isPrimary.checked = false;
    };

    const closeQuickImage = () => {
        if (!quickImageModal) return;
        closeQuickImageViewer();
        quickImageModal.hidden = true;
        quickImageModal.setAttribute('aria-hidden', 'true');
        quickImageTrigger = null;
        quickImageDeleteUrl = '';
        quickImageTargets = {};
        quickImageCompletedRoles.clear();
        document.body.classList.remove('rfm-quick-image-open');
    };

    quickImageForm?.addEventListener('rfm:file-preview-changed', (event) => {
        setQuickImageNextPreview(event.detail?.src || null);
    });

    quickImageRoleChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            setQuickImageRole(chip.dataset.rfmQuickImageRoleChip || '');
        });
    });

    quickImageWorkflowSteps.forEach((step) => {
        step.addEventListener('click', () => {
            setQuickImageRole(step.dataset.rfmQuickImageWorkflowStep || '');
        });
    });

    quickImageRolePicker?.addEventListener('change', () => {
        setQuickImageRole(quickImageRolePicker.value || '');
    });

    quickImageUsagePicker?.addEventListener('change', () => {
        if (quickImageUsageHidden) {
            quickImageUsageHidden.value = quickImageUsagePicker.value || 'all';
        }
    });

    root.querySelectorAll('[data-rfm-quick-image-view-open]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            openQuickImageViewer(button.dataset.rfmQuickImageViewOpen || 'current');
        });
    });

    root.querySelectorAll('[data-rfm-quick-image-viewer-close]').forEach((button) => {
        button.addEventListener('click', closeQuickImageViewer);
    });

    quickImageViewerPrev?.addEventListener('click', (event) => {
        event.stopPropagation();
        stepQuickImageViewer(-1);
    });

    quickImageViewerNext?.addEventListener('click', (event) => {
        event.stopPropagation();
        stepQuickImageViewer(1);
    });

    document.addEventListener('keydown', (event) => {
        if (quickImageViewer?.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeQuickImageViewer();
        } else if (event.key === 'ArrowLeft') {
            stepQuickImageViewer(-1);
        } else if (event.key === 'ArrowRight') {
            stepQuickImageViewer(1);
        }
    });

    quickImageDeleteBtn?.addEventListener('click', async () => {
        if (!quickImageDeleteUrl || !window.confirm('Remove this image from the SKU?')) return;
        quickImageDeleteBtn.disabled = true;
        setQuickImageStatus('Removing image...');
        try {
            const formData = new FormData();
            formData.append('_token', csrf);
            formData.append('_method', 'DELETE');
            const response = await fetch(quickImageDeleteUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Unable to remove image.');
            quickImageDeleteUrl = '';
            const currentRole = quickImageRoleSelect?.value || '';
            if (quickImageTargets[currentRole]) {
                quickImageTargets[currentRole].currentUrl = '';
                quickImageTargets[currentRole].mediaId = '';
                quickImageTargets[currentRole].deleteUrl = '';
                quickImageTargets[currentRole].mobileTargetType = 'retail_product';
                quickImageTargets[currentRole].mobileTargetId = quickImageTrigger?.dataset.mobileTargetId || '';
                quickImageTargets[currentRole].count = 0;
                quickImageTargets[currentRole].images = [];
            }
            if (quickImageTrigger) {
                quickImageTrigger.dataset.currentImageUrl = '';
                delete quickImageTrigger.dataset.mediaId;
                delete quickImageTrigger.dataset.imageDeleteUrl;
                const skuItem = quickImageTrigger.closest('[data-rfm-sku]');
                if (skuItem) skuItem.dataset.rfmNeedsImage = '1';
            }
            updateQuickImageCurrentPreview('');
            renderQuickImageSavedPhotos(currentRole);
            setQuickImageStatus('Image removed.');
            showToast(data.message || 'Image removed.');
        } catch (error) {
            setQuickImageStatus(error.message || 'Unable to remove image.', true);
            showToast(error.message || 'Unable to remove image.', true);
        } finally {
            quickImageDeleteBtn.disabled = false;
        }
    });

    root.querySelectorAll('[data-rfm-quick-image-close]').forEach((button) => {
        button.addEventListener('click', closeQuickImage);
    });

    quickImageModal?.querySelector('.rfm-quick-backdrop')?.addEventListener('click', closeQuickImage);

    root.querySelectorAll('[data-rfm-quick-image-open]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!quickImageModal || !quickImageForm) return;

            quickImageTrigger = button;
            quickImageTargets = safeParseQuickImageTargets(button.dataset.rfmQuickImageTargets || '{}');
            quickImageDeleteUrl = button.dataset.imageDeleteUrl || '';
            quickImageCompletedRoles.clear();
            quickImageForm.reset();
            quickImageForm.dispatchEvent(new CustomEvent('rfm:file-preview-clear', { bubbles: true }));
            setQuickImageNextPreview(null);
            if (quickImageTitle) quickImageTitle.textContent = button.dataset.productTitle || 'Add image';
            if (quickImageUsageHidden) quickImageUsageHidden.value = 'all';
            if (quickImageUsagePicker) quickImageUsagePicker.value = 'all';
            setQuickImageStatus('');
            setQuickImageRole(button.dataset.imageRole || 'main');

            quickImageModal.hidden = false;
            quickImageModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('rfm-quick-image-open');
            quickImageModal.querySelector('[data-rfm-camera]')?.focus();
        });
    });

    quickImageForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = quickImageForm.querySelector('[data-rfm-quick-image-submit]');
        const originalLabel = submitButton?.textContent || 'Add image';
        const cameraInput = quickImageForm.querySelector('[data-rfm-camera]');
        const uploadInput = quickImageForm.querySelector('[data-rfm-upload]');
        const currentRole = quickImageRoleSelect?.value || '';
        const currentRoleLabel = quickImageRoleSelect?.selectedOptions?.[0]?.textContent?.trim() || currentRole;

        if (quickImageUsageHidden && quickImageUsagePicker) {
            quickImageUsageHidden.value = quickImageUsagePicker.value || quickImageUsageHidden.value || 'all';
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }
        let savedSuccessfully = false;

        const uploadFile = selectedMediaUploadFile(cameraInput, uploadInput);
        const payload = new FormData(quickImageForm);
        if (uploadFile) {
            if (submitButton) submitButton.textContent = 'Preparing image...';
            setQuickImageStatus('Preparing image...');
            const preparedFile = await normalizeImageUploadFile(uploadFile);
            payload.set('uploaded_image', preparedFile, preparedFile.name || uploadFile.name);
        }
        payload.delete('uploaded_image_alt');

        if (submitButton) submitButton.textContent = 'Saving...';
        setQuickImageStatus('Saving image...');

        try {
            const uploadUrl = quickImageForm.action || '';
            if (!uploadUrl || uploadUrl.endsWith('#')) {
                throw new Error('Unable to save image. The upload URL is missing. Close the image panel, reopen this SKU, then try again.');
            }

            const response = await fetch(uploadUrl, {
                method: 'POST',
                body: payload,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await jsonResponseOrText(response);
            if (!response.ok) {
                throw new Error(uploadFailureMessage(response, data, 'Unable to save image.', uploadUrl));
            }

            quickImageCompletedRoles.add(currentRole);
            savedSuccessfully = true;
            showToast(`Saved as ${currentRoleLabel}.`);

            const media = data.media || {};
            if (media.url) {
                const roleTarget = quickImageTargets[currentRole] || {};
                const savedImage = {
                    id: media.id || roleTarget.mediaId || '',
                    url: media.url,
                    label: media.alt_text || `${currentRoleLabel} photo`,
                    deleteUrl: media.delete_url || roleTarget.deleteUrl || '',
                };

                roleTarget.currentUrl = media.url;
                roleTarget.mediaId = media.id || roleTarget.mediaId || '';
                roleTarget.deleteUrl = media.delete_url || roleTarget.deleteUrl || '';
                roleTarget.images = roleShouldReplaceSingleImage(currentRole)
                    ? [savedImage]
                    : [...(Array.isArray(roleTarget.images) ? roleTarget.images : []), savedImage];
                roleTarget.count = media.count ?? roleTarget.images.length;
                if (media.mobile_target_type) roleTarget.mobileTargetType = media.mobile_target_type;
                if (media.mobile_target_id) roleTarget.mobileTargetId = media.mobile_target_id;
                quickImageTargets[currentRole] = roleTarget;
                persistQuickImageTargetsToTrigger();

                if (roleShouldReplaceSingleImage(currentRole)) {
                    updateQuickImageCurrentPreview(media.url);
                    updateQuickImageRowThumb(quickImageTrigger, media.url, media.id, media.delete_url);
                    if (media.delete_url) {
                        quickImageDeleteUrl = media.delete_url;
                    }
                }
            }

            const nextRole = QUICK_IMAGE_AUTO_SEQUENCE[currentRole];
            let nextRoleLabel = currentRoleLabel;
            resetQuickImageInputsAfterSave();
            syncQuickImageRoleUi(currentRole);
            syncQuickImageTarget(currentRole);

            if (nextRole && nextRole !== currentRole) {
                const nextOption = quickImageRoleSelect?.querySelector(`option[value="${nextRole}"]`);
                nextRoleLabel = nextOption?.textContent?.trim() || nextRole;
            }

            setQuickImageStatus(
                nextRole && nextRole !== currentRole
                    ? `Saved as ${currentRoleLabel}. Next: ${nextRoleLabel} — add another photo or close.`
                    : `Saved as ${currentRoleLabel}. Add another or close when done.`,
            );

            if (nextRole && nextRole !== currentRole) {
                setQuickImageStatus(`Saved as ${currentRoleLabel}. Next: ${nextRoleLabel} when ready.`);
            }

            const activePanel = quickImageForm.querySelector('.rfm-media-tab-panel.is-active');
            activePanel?.querySelector('input, textarea, button')?.focus();
        } catch (error) {
            setQuickImageStatus(error.message || 'Unable to save image.', true);
            showToast(error.message || 'Unable to save image.', true);
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                if (!savedSuccessfully) {
                    submitButton.textContent = originalLabel;
                }
            }
        }
    });

    const barcodeModal = root.querySelector('[data-rfm-barcode-modal]');
    const barcodeInput = root.querySelector('[data-rfm-barcode-input]');
    const barcodeTitle = root.querySelector('[data-rfm-barcode-title]');
    const barcodeVariants = root.querySelector('[data-rfm-barcode-variants]');
    const barcodeStatus = root.querySelector('[data-rfm-barcode-status]');
    const barcodeModeTabs = root.querySelector('[data-rfm-barcode-mode-tabs]');
    const barcodeKeyboardPanel = root.querySelector('[data-rfm-barcode-keyboard-panel]');
    const barcodeCameraPanel = root.querySelector('[data-rfm-barcode-camera-panel]');
    const barcodeCameraVideo = root.querySelector('[data-rfm-barcode-video]');
    const barcodeCameraJump = root.querySelector('[data-rfm-barcode-camera-jump]');
    const barcodeModeButtons = root.querySelectorAll('[data-rfm-barcode-mode]');
    let barcodeAction = '';
    let barcodeButton = null;
    let barcodeTimer = null;
    let barcodeSaving = false;
    let barcodeCameraSession = null;
    let barcodeInputMode = 'keyboard';

    const stopBarcodeCamera = () => {
        barcodeCameraSession?.close();
        barcodeCameraSession = null;
    };

    const applyBarcodeInputValue = (value) => {
        if (!barcodeInput) return;
        barcodeInput.value = value;
        barcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const setBarcodeInputMode = (mode, { focusKeyboard = false, persist = true } = {}) => {
        const nextMode = mode === 'camera' && cameraBarcodeSupported() ? 'camera' : 'keyboard';
        barcodeInputMode = nextMode;

        if (persist) {
            try {
                sessionStorage.setItem(RFM_BARCODE_MODE_KEY, nextMode);
            } catch {
                // ignore storage errors
            }
        }

        barcodeModeButtons.forEach((button) => {
            const active = button.dataset.rfmBarcodeMode === nextMode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (barcodeKeyboardPanel) {
            barcodeKeyboardPanel.hidden = nextMode !== 'keyboard';
        }
        if (barcodeCameraPanel) {
            barcodeCameraPanel.hidden = nextMode !== 'camera';
        }

        if (nextMode === 'camera') {
            stopBarcodeCamera();
            if (!barcodeCameraVideo) return;
            setBarcodeStatus('Camera on — point at the barcode…');
            barcodeCameraSession = createCameraBarcodeSession({
                video: barcodeCameraVideo,
                onDetected: (value) => {
                    applyBarcodeInputValue(value);
                    setBarcodeStatus('Barcode detected. Saving…');
                    stopBarcodeCamera();
                    setBarcodeInputMode('keyboard', { focusKeyboard: true, persist: true });
                },
                onError: (message) => {
                    setBarcodeStatus(message, true);
                    showToast(message, true);
                    setBarcodeInputMode('keyboard', { focusKeyboard: true, persist: false });
                },
            });
            barcodeCameraSession.start();
            return;
        }

        stopBarcodeCamera();
        if (focusKeyboard && barcodeInput) {
            window.setTimeout(() => {
                barcodeInput.focus();
                barcodeInput.select();
            }, 50);
        }
    };

    const readStoredBarcodeMode = () => {
        try {
            return sessionStorage.getItem(RFM_BARCODE_MODE_KEY) === 'camera' ? 'camera' : 'keyboard';
        } catch {
            return 'keyboard';
        }
    };

    const initBarcodeModeUi = () => {
        const supported = cameraBarcodeSupported();
        if (barcodeModeTabs) {
            barcodeModeTabs.hidden = !supported;
        }
        if (barcodeCameraJump) {
            barcodeCameraJump.hidden = !supported;
        }
        if (!supported && barcodeInputMode === 'camera') {
            barcodeInputMode = 'keyboard';
        }
    };

    initBarcodeModeUi();

    const closeBarcode = () => {
        if (!barcodeModal) return;
        stopBarcodeCamera();
        barcodeModal.hidden = true;
        barcodeModal.setAttribute('aria-hidden', 'true');
        window.clearTimeout(barcodeTimer);
        setBarcodeInputMode('keyboard', { focusKeyboard: false, persist: false });
    };

    const setBarcodeStatus = (message, isError = false) => {
        if (!barcodeStatus) return;
        barcodeStatus.textContent = message;
        barcodeStatus.classList.toggle('is-error', isError);
    };

    const barcodeDigitsOnly = (value) => (value || '').replace(/\D+/g, '');

    const isValidEan13 = (digits) => {
        if (digits.length !== 13) return false;
        let sum = 0;
        for (let i = 0; i < 12; i += 1) {
            sum += Number(digits[i]) * (i % 2 === 0 ? 1 : 3);
        }
        const check = (10 - (sum % 10)) % 10;
        return check === Number(digits[12]);
    };

    const isPlausibleBarcode = (value) => {
        const raw = (value || '').trim();
        if (!raw) return false;
        if (/^LHC\d{8}$/i.test(raw)) return true;
        const digits = barcodeDigitsOnly(raw);
        if (digits.length === 13) return isValidEan13(digits);
        return digits.length === 8 || digits.length === 12;
    };

    const barcodeConflictLabel = (item) => {
        const values = [...item.querySelectorAll('.rfm-row-variant-value')]
            .map((el) => el.textContent.trim())
            .filter(Boolean);
        if (values.length > 0) return values.join(' · ');
        return item.querySelector('.rfm-row-title')?.textContent?.trim() || 'another SKU';
    };

    const findFamilyBarcodeConflict = (value, currentProductId) => {
        const normalized = value.trim().toLowerCase();
        if (!normalized) return null;
        for (const item of getSkuItems()) {
            const existing = (item.dataset.rfmBarcode || '').trim().toLowerCase();
            if (!existing || existing !== normalized) continue;
            if (String(item.dataset.rfmProductId || '') === String(currentProductId || '')) continue;
            return item;
        }
        return null;
    };

    const validateBarcodeForSave = (value) => {
        const trimmed = value.trim();
        if (trimmed.length < 6) {
            return { ok: false, waiting: true, message: 'Waiting for barcode...' };
        }
        if (!isPlausibleBarcode(trimmed)) {
            return {
                ok: false,
                message: 'Invalid barcode. Check the scan (EAN-8/12/13 or LHC code).',
            };
        }
        const productId = barcodeButton?.closest('[data-rfm-sku]')?.dataset.rfmProductId;
        const conflict = findFamilyBarcodeConflict(trimmed, productId);
        if (conflict) {
            return {
                ok: false,
                message: `Barcode already used on ${barcodeConflictLabel(conflict)} in this family.`,
            };
        }
        return { ok: true, value: trimmed };
    };

    const saveBarcode = async () => {
        if (!barcodeAction || !barcodeInput || barcodeSaving) return;
        const check = validateBarcodeForSave(barcodeInput.value);
        if (!check.ok) {
            setBarcodeStatus(check.message, !check.waiting);
            if (!check.waiting) {
                showToast(check.message, true);
            }
            return;
        }

        barcodeSaving = true;
        setBarcodeStatus('Saving barcode...');
        try {
            await saveProductField(barcodeAction, 'barcode', check.value);
            const skuItem = barcodeButton?.closest('[data-rfm-sku]');
            if (skuItem) {
                skuItem.dataset.rfmNeedsBarcode = '0';
                skuItem.dataset.rfmBarcode = check.value;
                const barcodeField = skuItem.querySelector('input[name="barcode"]');
                if (barcodeField) barcodeField.value = check.value;
            }
            if (barcodeButton) {
                barcodeButton.textContent = check.value;
                barcodeButton.dataset.rfmCurrentBarcode = check.value;
                barcodeButton.classList.add('has-barcode');
            }
            refreshBarcodeDuplicateFlags();
            setBarcodeStatus('Saved.');
            showToast('Barcode saved.');
            window.setTimeout(closeBarcode, 350);
            applyFilters();
        } catch (error) {
            setBarcodeStatus(error.message || 'Unable to save barcode.', true);
            showToast(error.message || 'Unable to save barcode.', true);
        } finally {
            barcodeSaving = false;
        }
    };

    root.querySelectorAll('[data-rfm-barcode-close]').forEach((button) => {
        button.addEventListener('click', closeBarcode);
    });

    root.querySelectorAll('[data-rfm-barcode-open]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!barcodeModal || !barcodeInput) return;

            barcodeButton = button;
            barcodeAction = button.dataset.rfmAction || '';
            barcodeInput.value = button.dataset.rfmCurrentBarcode || '';
            const variantTitle = button.dataset.rfmVariantTitle || 'Scan barcode';
            if (barcodeTitle) {
                barcodeTitle.textContent = variantTitle;
            }
            if (barcodeVariants) {
                barcodeVariants.replaceChildren();
                let chips = [];
                try {
                    chips = JSON.parse(button.dataset.rfmVariantChips || '[]');
                } catch {
                    chips = [];
                }
                if (chips.length === 0) {
                    const fallback = document.createElement('p');
                    fallback.className = 'rfm-barcode-variants-fallback';
                    fallback.textContent = variantTitle;
                    barcodeVariants.appendChild(fallback);
                } else {
                    chips.forEach((chip) => {
                        const item = document.createElement('span');
                        item.className = 'rfm-barcode-variant-chip';
                        const axis = document.createElement('em');
                        axis.textContent = chip.group || '';
                        const value = document.createElement('strong');
                        value.textContent = chip.label || '';
                        item.append(axis, value);
                        barcodeVariants.appendChild(item);
                    });
                }
            }
            setBarcodeStatus('Waiting for barcode...');
            barcodeModal.hidden = false;
            barcodeModal.setAttribute('aria-hidden', 'false');
            const preferredMode = readStoredBarcodeMode();
            setBarcodeInputMode(preferredMode, {
                focusKeyboard: preferredMode === 'keyboard',
                persist: false,
            });
        });
    });

    barcodeModeButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const mode = button.dataset.rfmBarcodeMode || 'keyboard';
            setBarcodeInputMode(mode, { focusKeyboard: mode === 'keyboard', persist: true });
            if (mode === 'keyboard') {
                setBarcodeStatus('Waiting for barcode...');
            }
        });
    });

    barcodeCameraJump?.addEventListener('click', (event) => {
        event.preventDefault();
        setBarcodeInputMode('camera', { persist: true });
    });

    barcodeInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            window.clearTimeout(barcodeTimer);
            saveBarcode();
        }
        if (event.key === 'Escape') {
            closeBarcode();
        }
    });

    barcodeInput?.addEventListener('input', () => {
        window.clearTimeout(barcodeTimer);
        const check = validateBarcodeForSave(barcodeInput.value);
        if (!check.ok) {
            setBarcodeStatus(check.message, !check.waiting);
            return;
        }
        setBarcodeStatus('Barcode received. Saving...');
        barcodeTimer = window.setTimeout(saveBarcode, 500);
    });

    // ── Quick price entry modal ──
    const priceModal    = root.querySelector('[data-rfm-price-modal]');
    const priceInput    = root.querySelector('[data-rfm-price-input]');
    const priceTitle    = root.querySelector('[data-rfm-price-title]');
    const priceStatus   = root.querySelector('[data-rfm-price-status]');
    const priceSaveBtn  = root.querySelector('[data-rfm-price-save]');
    let priceAction     = '';
    let priceButton     = null;
    let priceSaving     = false;

    const closePrice = () => {
        if (!priceModal) return;
        priceModal.hidden = true;
        priceModal.setAttribute('aria-hidden', 'true');
    };

    const setPriceStatus = (message, isError = false) => {
        if (!priceStatus) return;
        priceStatus.textContent = message;
        priceStatus.classList.toggle('is-error', isError);
    };

    const savePrice = async () => {
        if (!priceAction || !priceInput || priceSaving) return;
        const raw = priceInput.value.trim();
        const value = parseFloat(raw);
        if (raw === '' || isNaN(value) || value < 0) {
            setPriceStatus('Enter a valid price.');
            priceInput.focus();
            return;
        }

        priceSaving = true;
        if (priceSaveBtn) priceSaveBtn.disabled = true;
        setPriceStatus('Saving…');

        try {
            await saveProductField(priceAction, 'retail_price', value.toFixed(2));

            // Update the price chip in the row
            if (priceButton) {
                const formatted = '£' + value.toFixed(2);
                priceButton.textContent = formatted;
                priceButton.dataset.rfmCurrentPrice = value.toFixed(2);
                priceButton.classList.remove('is-missing');
                priceButton.setAttribute('aria-label', 'Edit price for ' + (priceButton.dataset.rfmProductTitle || ''));
            }

            // Sync the price input inside the expanded edit panel (if open)
            const skuItem = priceButton?.closest('[data-rfm-sku]');
            if (skuItem) {
                skuItem.dataset.rfmNeedsPrice = '0';
                const panelPriceInput = skuItem.querySelector('input[name="retail_price"]');
                if (panelPriceInput) panelPriceInput.value = value.toFixed(2);
            }

            showToast('Price saved.');
            setPriceStatus('Saved!');
            window.setTimeout(closePrice, 350);
            applyFilters();
        } catch (error) {
            setPriceStatus(error.message || 'Unable to save price.', true);
            showToast(error.message || 'Unable to save price.', true);
        } finally {
            priceSaving = false;
            if (priceSaveBtn) priceSaveBtn.disabled = false;
        }
    };

    // Prevent <details> from toggling when clicking action buttons inside <summary>.
    // Use capture phase so we intercept early; call only preventDefault (not stopPropagation)
    // so the event still reaches child button handlers.
    root.querySelectorAll('.rfm-sku-summary').forEach((summary) => {
        summary.addEventListener('click', (event) => {
            if (event.target.closest('[data-rfm-price-open], [data-rfm-barcode-open], [data-rfm-quick-image-open], [data-rfm-sku-delete]')) {
                event.preventDefault();
                // Do NOT call stopPropagation here — the event must still reach child handlers
            }
        }, true /* capture phase */);
    });

    root.querySelectorAll('[data-rfm-sku-delete]').forEach((btn) => {
        btn.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            const title = btn.dataset.rfmProductTitle || 'this SKU';
            if (!window.confirm(`Delete ${title}? This cannot be undone.`)) {
                return;
            }

            const action = btn.dataset.rfmAction || '';
            if (!action) {
                return;
            }

            const skuItem = btn.closest('[data-rfm-sku]');
            btn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('_token', csrf);
                formData.append('_method', 'DELETE');

                const response = await fetch(action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Unable to delete SKU.');
                }

                skuItem?.remove();

                root.querySelectorAll('[data-rfm-sku-group]').forEach((group) => {
                    const remaining = group.querySelectorAll('[data-rfm-sku]').length;
                    if (remaining === 0) {
                        group.remove();
                    }
                });

                applyFilters();
                showToast(data.message || 'SKU removed.');
            } catch (error) {
                btn.disabled = false;
                showToast(error.message || 'Unable to delete SKU.', true);
            }
        });
    });

    root.querySelectorAll('[data-rfm-price-close]').forEach((btn) => {
        btn.addEventListener('click', closePrice);
    });

    root.querySelectorAll('[data-rfm-price-open]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!priceModal || !priceInput) return;

            priceButton = btn;
            priceAction = btn.dataset.rfmAction || '';
            priceInput.value = btn.dataset.rfmCurrentPrice || '';
            if (priceTitle) priceTitle.textContent = btn.dataset.rfmProductTitle || 'Set price';
            setPriceStatus('');
            priceModal.hidden = false;
            priceModal.setAttribute('aria-hidden', 'false');
            window.setTimeout(() => {
                priceInput.focus();
                priceInput.select();
            }, 50);
        });
    });

    priceInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            savePrice();
        }
        if (event.key === 'Escape') {
            closePrice();
        }
    });

    priceSaveBtn?.addEventListener('click', () => savePrice());

    priceModal?.querySelector('.rfm-quick-backdrop')?.addEventListener('click', closePrice);

    // Row-level POS / website buttons
    root.querySelectorAll('[data-rfm-row-toggle]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            const toggleRow = button.closest('[data-rfm-row-toggles]');
            const skuItem = button.closest('[data-rfm-sku]');
            const action = toggleRow?.dataset.rfmAction;
            const field = button.dataset.rfmField;
            if (!action || !field) return;

            const previous = button.getAttribute('aria-pressed') === 'true';
            const next = !previous;

            button.disabled = true;
            button.classList.add('is-saving');
            updateSkuToggleState(skuItem, field, next);

            try {
                await saveProductFlag(action, field, next);
                showToast('Saved.');
            } catch (err) {
                updateSkuToggleState(skuItem, field, previous);
                showToast(err.message || 'Save failed.', true);
            } finally {
                button.disabled = false;
                button.classList.remove('is-saving');
                applyFilters();
            }
        });
    });

    // Autosave toggles
    root.querySelectorAll('[data-rfm-toggles]').forEach((toggleRow) => {
        const action = toggleRow.dataset.rfmAction;
        if (!action) return;

        toggleRow.querySelectorAll('[data-rfm-toggle]').forEach((input) => {
            input.addEventListener('change', async () => {
                const field = input.dataset.rfmToggle;
                const wrapper = input.closest('.rfm-tog');
                wrapper?.classList.add('is-saving');
                wrapper?.classList.toggle('is-on', input.checked);

                try {
                    const skuItem = input.closest('[data-rfm-sku]');
                    await saveProductFlag(action, field, input.checked);
                    updateSkuToggleState(skuItem, field, input.checked);
                    showToast('Saved.');
                } catch (err) {
                    input.checked = !input.checked;
                    wrapper?.classList.toggle('is-on', input.checked);
                    updateSkuToggleState(input.closest('[data-rfm-sku]'), field, input.checked);
                    showToast(err.message || 'Save failed.', true);
                } finally {
                    wrapper?.classList.remove('is-saving');
                    applyFilters();
                }
            });
        });
    });

    // Stock stepper
    root.querySelectorAll('[data-rfm-stepper-form]').forEach((form) => {
        const input = form.querySelector('[data-rfm-stock-input]');
        if (!input) return;
        form.querySelectorAll('[data-rfm-step]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const delta = Number(btn.dataset.rfmStep) || 0;
                const current = Number(input.value) || 0;
                input.value = current + delta;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });

    initVariantOptionManagers(root, csrf, showToast);
    initAddSkuPreview(root);
    initAddSkuDuplicateToggle(root);
    initVariantRefreshSkus(root);
    initVariantOptionCreateSkus(root);
    initVariantModelChips(root, csrf, showToast);
    initFamilyEcommercePreview(root);
    initFamilyDisplayNameEditor(root, csrf, showToast);
    initFamilySharedPanels(root);

    if (window.location.hash) {
        const target = document.getElementById(window.location.hash.slice(1));
        if (target?.tagName === 'DETAILS') {
            target.open = true;
            window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
        }
    }

    const skusWorkspace = document.getElementById('rfm-skus-workspace');
    if (skusWorkspace && new URLSearchParams(window.location.search).get('focus') === 'skus') {
        window.setTimeout(() => {
            skusWorkspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 120);
    }

    initLocationSectionCascade(root);
    initAiDescription(root, csrf, showToast);
};

// ─────────────────────────────────────────────────────────────────────────────
// Family shared details: AI-generate ecommerce description
// Uses Gemini via OpenRouter (web-search-enabled) to fetch the brand's
// own product description, strip foreign retailer mentions, and pre-fill
// the textarea. Author keeps the option to edit before saving.
// ─────────────────────────────────────────────────────────────────────────────
const initAiDescription = (root, csrf, showToast) => {
    const block = root.querySelector('[data-rfm-ai-description]');
    if (!block) return;

    const url = block.dataset.rfmAiDescriptionUrl;
    const button = block.querySelector('[data-rfm-ai-description-generate]');
    const buttonLabel = block.querySelector('.rfm-ai-description-btn-label');
    const textarea = block.querySelector('[data-rfm-ai-description-textarea]');
    const feedback = block.querySelector('[data-rfm-ai-description-feedback]');
    const confidenceEl = block.querySelector('[data-rfm-ai-description-confidence]');
    const statusEl = block.querySelector('[data-rfm-ai-description-status]');
    const sourcesEl = block.querySelector('[data-rfm-ai-description-sources]');
    if (!url || !button || !textarea || !feedback) return;

    const setBusy = (busy) => {
        button.disabled = busy;
        button.classList.toggle('is-busy', busy);
        if (buttonLabel) buttonLabel.textContent = busy ? 'Searching the web…' : 'Generate with AI';
    };

    const renderConfidence = (grade) => {
        if (!confidenceEl) return;
        confidenceEl.textContent = `Confidence ${grade || '?'}`;
        confidenceEl.dataset.grade = grade || '';
    };

    const renderSources = (sources) => {
        if (!sourcesEl) return;
        sourcesEl.innerHTML = '';
        const list = Array.isArray(sources) ? sources : [];
        if (list.length === 0) {
            sourcesEl.hidden = true;
            return;
        }
        list.slice(0, 5).forEach((url) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            let label = url;
            try { label = new URL(url).hostname.replace(/^www\./, ''); } catch { /* keep url */ }
            a.textContent = label;
            li.appendChild(a);
            sourcesEl.appendChild(li);
        });
        sourcesEl.hidden = false;
    };

    const confirmReplace = () => {
        const current = (textarea.value || '').trim();
        if (!current) return true;
        return window.confirm('Replace the current description with the AI-generated text?');
    };

    button.addEventListener('click', async () => {
        if (!confirmReplace()) return;

        setBusy(true);
        feedback.hidden = false;
        renderConfidence('');
        if (statusEl) statusEl.textContent = 'Searching the web for the brand\'s own description…';
        renderSources([]);

        try {
            const formData = new FormData();
            formData.append('_token', csrf);

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const errMsg = data.message || Object.values(data.errors || {}).flat()[0] || `Request failed (${response.status}).`;
                throw new Error(errMsg);
            }

            const description = String(data.description || '').trim();
            if (!description) {
                throw new Error('The AI returned an empty description. Try again.');
            }

            textarea.value = description;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));

            renderConfidence(data.confidence);
            renderSources(data.source_urls);

            if (statusEl) {
                const usedSearch = data.used_search === true || data.used_search === 1;
                const parts = [];
                parts.push(usedSearch ? 'Generated from web sources.' : 'Generated from product fields (no web evidence found).');
                if (data.notes) parts.push(data.notes);
                statusEl.textContent = parts.join(' ');
            }

            showToast('AI description ready — review and save.');
        } catch (err) {
            if (statusEl) statusEl.textContent = err.message || 'Could not generate a description.';
            showToast(err.message || 'AI description failed.', true);
        } finally {
            setBusy(false);
        }
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Family shared details: cascade section select when store changes
// ─────────────────────────────────────────────────────────────────────────────
const initLocationSectionCascade = (root) => {
    const field = root.querySelector('[data-rfm-location-field]');
    if (!field) return;

    const locationSelect = field.querySelector('[data-rfm-location-select]');
    const sectionSelect  = field.querySelector('[data-rfm-section-select]');
    const applyCheck     = field.querySelector('[data-rfm-apply-location]');
    if (!locationSelect || !sectionSelect) return;

    /** @type {Record<string, {id: number, name: string}[]>} */
    const allSections = (() => {
        try { return JSON.parse(field.dataset.allSections || '{}'); } catch { return {}; }
    })();

    const rebuildSections = (locationId, keepSectionId) => {
        const sections = allSections[String(locationId)] || [];
        sectionSelect.innerHTML = '<option value="">No section</option>';
        sections.forEach(({ id, name }) => {
            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = name;
            opt.selected = keepSectionId !== null && String(id) === String(keepSectionId);
            sectionSelect.appendChild(opt);
        });
        sectionSelect.disabled = sections.length === 0;
    };

    locationSelect.addEventListener('change', () => {
        rebuildSections(locationSelect.value, null);
        // Ticking the apply checkbox when a store is picked is a helpful shortcut.
        if (applyCheck && locationSelect.value) applyCheck.checked = true;
    });

    // Initialise section list on page load (in case old() re-populated the location).
    rebuildSections(locationSelect.value, sectionSelect.dataset.currentSection || null);
};

// ─────────────────────────────────────────────────────────────────────────────
// Family page: collapsed accordions by default; auto-enable Apply when values are set
// ─────────────────────────────────────────────────────────────────────────────
const initFamilySharedPanels = (root) => {
    root.querySelectorAll('details').forEach((detail) => {
        detail.open = false;
    });

    const sharedForm = root.querySelector('.rfm-family-shared-form');
    if (!sharedForm) return;

    const fieldApplyPairs = [
        ['department', 'apply_department'],
        ['product_type', 'apply_product_type'],
        ['description', 'apply_description'],
        ['retail_price', 'apply_retail_price'],
        ['cost_price', 'apply_cost_price'],
        ['vat_rate', 'apply_vat_rate'],
        ['stock_quantity', 'apply_stock_quantity'],
        ['shelf_location', 'apply_shelf_location'],
        ['supplier', 'apply_supplier'],
        ['supplier_product_code', 'apply_supplier_product_code'],
    ];

    fieldApplyPairs.forEach(([fieldName, applyName]) => {
        const input = sharedForm.querySelector(`[name="${fieldName}"]`);
        const apply = sharedForm.querySelector(`[name="${applyName}"]`);
        if (!input || !apply) return;

        const syncApply = () => {
            if (String(input.value || '').trim() !== '') {
                apply.checked = true;
            }
        };

        input.addEventListener('input', syncApply);
        input.addEventListener('change', syncApply);
    });

    const locationSelect = sharedForm.querySelector('[data-rfm-location-select]');
    const locationApply = sharedForm.querySelector('[name="apply_inventory_location"]');
    if (locationSelect && locationApply) {
        const syncLocationApply = () => {
            if (locationSelect.value) {
                locationApply.checked = true;
            }
        };
        locationSelect.addEventListener('change', syncLocationApply);
    }
};

// ─────────────────────────────────────────────────────────────────────────────
// Family hero: edit final display name (+ optional universal sellable rename)
// ─────────────────────────────────────────────────────────────────────────────
const initFamilyDisplayNameEditor = (root, csrf, showToast) => {
    const url = root.dataset.rfmDisplayNameUrl;
    const modal = root.querySelector('[data-rfm-display-name-modal]');
    if (!url || !modal) return;

    const form = modal.querySelector('[data-rfm-display-name-form]');
    const input = modal.querySelector('[data-rfm-display-name-input]');
    const saveBtn = modal.querySelector('[data-rfm-display-name-save]');
    const universalCheckbox = modal.querySelector('[data-rfm-display-name-universal]');
    const heading = root.querySelector('[data-rfm-display-name-heading]');
    const crumb = root.querySelector('[data-rfm-display-name-crumb]');

    const openModal = () => {
        if (input && heading) {
            input.value = heading.textContent.trim();
        }
        modal.hidden = false;
        modal.removeAttribute('aria-hidden');
        input?.focus();
        input?.select();
    };

    const closeModal = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    };

    const applyDisplayNameToDom = (displayName) => {
        if (heading) heading.textContent = displayName;
        if (crumb) crumb.textContent = displayName;
        root.querySelectorAll('[data-rfm-family-name]').forEach((el) => {
            el.textContent = displayName;
        });
        root.querySelectorAll('[data-rfm-preview-name]').forEach((el) => {
            el.textContent = displayName;
        });
        const toolbarTitle = document.getElementById('rfm-ecom-preview-toolbar-title');
        if (toolbarTitle) toolbarTitle.textContent = displayName;
        const previewTitle = root.querySelector('[data-rfm-ecom-preview-title]');
        if (previewTitle) previewTitle.textContent = displayName;
        const previewBreadcrumb = root.querySelector('[data-rfm-ecom-preview-breadcrumb]');
        if (previewBreadcrumb && !previewBreadcrumb.dataset.rfmEcomPreviewBreadcrumbLocked) {
            previewBreadcrumb.textContent = displayName;
        }
        if (document.title.includes(' - Final Product Records')) {
            document.title = `${displayName} - Final Product Records`;
        }
    };

    root.querySelectorAll('[data-rfm-display-name-open]').forEach((btn) => {
        btn.addEventListener('click', openModal);
    });

    modal.querySelectorAll('[data-rfm-display-name-close]').forEach((btn) => {
        btn.addEventListener('click', closeModal);
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const displayName = input?.value.trim() || '';
        if (!displayName) {
            showToast('Enter a product name.', true);
            input?.focus();
            return;
        }

        if (saveBtn) saveBtn.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    display_name: displayName,
                    apply_to_matching_sellables: universalCheckbox?.checked ? 1 : 0,
                }),
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const firstError = payload?.errors
                    ? Object.values(payload.errors).flat()[0]
                    : null;
                throw new Error(firstError || payload.message || 'Could not save product name.');
            }

            closeModal();

            if ((payload.sellables_updated || 0) > 0) {
                showToast(payload.message || 'Product name updated.');
                window.setTimeout(() => window.location.reload(), 400);
                return;
            }

            applyDisplayNameToDom(payload.display_name || displayName);
            showToast(payload.message || 'Product name updated.');
        } catch (error) {
            showToast(error.message || 'Could not save product name.', true);
        } finally {
            if (saveBtn) saveBtn.disabled = false;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Family hero: advanced ecommerce shop preview (per-SKU main / variant / gallery)
// ─────────────────────────────────────────────────────────────────────────────
const initFamilyEcommercePreview = (root) => {
    const overlay = root.querySelector('[data-rfm-ecom-preview]');
    const dataEl = document.getElementById('rfm-ecom-preview-data');
    if (!overlay || !dataEl) return;

    let data;
    try {
        data = JSON.parse(dataEl.textContent || '{}');
    } catch {
        return;
    }

    const mainWrap = overlay.querySelector('[data-rfm-ecom-preview-main]');
    const thumbsWrap = overlay.querySelector('[data-rfm-ecom-preview-thumbs]');
    const galleryCaption = overlay.querySelector('[data-rfm-ecom-preview-gallery-caption]');
    const titleEl = overlay.querySelector('[data-rfm-ecom-preview-title]');
    const breadcrumbEl = overlay.querySelector('[data-rfm-ecom-preview-breadcrumb]');
    const priceEl = overlay.querySelector('[data-rfm-ecom-preview-price]');
    const shortEl = overlay.querySelector('[data-rfm-ecom-preview-short]');
    const longEl = overlay.querySelector('[data-rfm-ecom-preview-long]');
    const skuNoteEl = overlay.querySelector('[data-rfm-ecom-preview-sku-note]');
    const swatchButtons = overlay.querySelectorAll('[data-rfm-ecom-preview-swatch]');
    const optionButtons = overlay.querySelectorAll('[data-rfm-ecom-preview-option]');

    const colourGroupId = Number(data.colourGroupId) || null;
    const allGroupIds = [
        ...(colourGroupId ? [colourGroupId] : []),
        ...(data.variants || []).map((g) => Number(g.id)),
    ];

    const selection = new Map();
    let gallerySlides = [];
    let activeSlideIndex = 0;

    const formatMoney = (value) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return null;
        }
        return `£${Number(value).toFixed(2)}`;
    };

    const formatPriceRange = () => {
        if (data.sharedPrice !== null && data.sharedPrice !== undefined) {
            return formatMoney(data.sharedPrice);
        }
        if (data.priceMin !== null && data.priceMax !== null && data.priceMin !== data.priceMax) {
            return `From ${formatMoney(data.priceMin)}`;
        }
        if (data.priceMin !== null && data.priceMin !== undefined) {
            return formatMoney(data.priceMin);
        }
        return 'Price not set';
    };

    const selectionComplete = () => allGroupIds.length === 0
        || allGroupIds.every((groupId) => selection.has(groupId));

    const scoreSku = (sku) => {
        let matched = 0;
        selection.forEach((optionId, groupId) => {
            if (sku.optionsByGroup?.[groupId] === optionId) {
                matched += 1;
            }
        });
        return { matched, total: selection.size, exact: matched === selection.size && selection.size > 0 };
    };

    const findBestSku = () => {
        if (!Array.isArray(data.skus) || selection.size === 0) {
            return { sku: null, partial: false };
        }

        let exactSku = null;
        let partialSku = null;
        let partialScore = -1;

        data.skus.forEach((sku) => {
            const { matched, exact } = scoreSku(sku);
            if (matched === 0) return;
            if (exact) {
                exactSku = sku;
                return;
            }
            if (matched > partialScore) {
                partialSku = sku;
                partialScore = matched;
            }
        });

        if (exactSku) {
            return { sku: exactSku, partial: false };
        }
        if (partialSku) {
            return { sku: partialSku, partial: true };
        }
        return { sku: null, partial: false };
    };

    const buildGallerySlides = (sku) => {
        const slides = [];
        const seen = new Set();
        const pushSlide = (item, role) => {
            if (!item?.url || seen.has(item.url)) return;
            seen.add(item.url);
            slides.push({ ...item, role });
        };

        if (sku?.media) {
            pushSlide(sku.media.main, 'main');
            (sku.media.gallery || []).forEach((item) => pushSlide(item, 'gallery'));
        }

        if (slides.length === 0) {
            (data.familyFallback || []).forEach((item) => pushSlide(item, 'fallback'));
        }

        return slides;
    };

    const zoomScale = 2.35;
    const zoomHoverQuery = window.matchMedia('(hover: hover) and (pointer: fine)');
    const zoomMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    const zoomEnabled = () => zoomHoverQuery.matches && !zoomMotionQuery.matches;

    const bindMainImageZoom = (zoomPane, img) => {
        if (!mainWrap || !zoomEnabled()) {
            mainWrap?.classList.remove('is-zoomable', 'is-zooming');
            return;
        }

        mainWrap.classList.add('is-zoomable');

        const onMove = (event) => {
            const rect = zoomPane.getBoundingClientRect();
            if (!rect.width || !rect.height) {
                return;
            }
            const x = Math.min(100, Math.max(0, ((event.clientX - rect.left) / rect.width) * 100));
            const y = Math.min(100, Math.max(0, ((event.clientY - rect.top) / rect.height) * 100));
            img.style.setProperty('--zoom-x', `${x}%`);
            img.style.setProperty('--zoom-y', `${y}%`);
            mainWrap.classList.add('is-zooming');
        };

        const onLeave = () => {
            mainWrap.classList.remove('is-zooming');
            img.style.removeProperty('--zoom-x');
            img.style.removeProperty('--zoom-y');
        };

        zoomPane.addEventListener('mousemove', onMove);
        zoomPane.addEventListener('mouseleave', onLeave);
    };

    const setMainImage = (slide) => {
        if (!mainWrap) return;
        mainWrap.innerHTML = '';
        mainWrap.classList.remove('is-zoomable', 'is-zooming');
        if (!slide?.url) {
            const empty = document.createElement('div');
            empty.className = 'rfm-ecom-preview-img-empty';
            empty.textContent = 'No main or gallery photo for this sellable SKU yet';
            mainWrap.append(empty);
            return;
        }

        const zoomPane = document.createElement('div');
        zoomPane.className = 'rfm-ecom-preview-zoom';
        zoomPane.dataset.rfmEcomPreviewZoom = '';

        const img = document.createElement('img');
        img.src = slide.url;
        img.alt = slide.alt || '';
        img.dataset.rfmEcomPreviewMainImg = '';
        img.style.setProperty('--zoom-scale', String(zoomScale));

        if (zoomEnabled()) {
            const hint = document.createElement('span');
            hint.className = 'rfm-ecom-preview-zoom-hint';
            hint.setAttribute('aria-hidden', 'true');
            hint.textContent = 'Hover to zoom';
            zoomPane.append(img, hint);
        } else {
            zoomPane.append(img);
        }

        mainWrap.append(zoomPane);
        bindMainImageZoom(zoomPane, img);
    };

    const renderGalleryThumbs = () => {
        if (!thumbsWrap) return;
        thumbsWrap.innerHTML = '';

        gallerySlides.forEach((slide, index) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `rfm-ecom-preview-thumb ${index === activeSlideIndex ? 'is-active' : ''}`;
            btn.dataset.rfmEcomPreviewThumb = '';
            btn.dataset.imageUrl = slide.url;
            btn.dataset.imageAlt = slide.alt || '';
            btn.dataset.imageRole = slide.role || '';
            btn.setAttribute('role', 'listitem');
            const roleLabel = slide.role === 'main' ? 'Main photo' : 'Gallery photo';
            btn.setAttribute('aria-label', `${roleLabel} ${index + 1} of ${gallerySlides.length}`);

            const img = document.createElement('img');
            img.src = slide.url;
            img.alt = '';
            img.loading = 'lazy';
            img.draggable = false;

            btn.append(img);
            btn.addEventListener('click', () => {
                activeSlideIndex = index;
                setMainImage(slide);
                renderGalleryThumbs();
                updateGalleryCaption();
            });
            thumbsWrap.append(btn);
        });

        thumbsWrap.hidden = gallerySlides.length <= 1;
    };

    const updateGalleryCaption = () => {
        if (!galleryCaption) return;
        if (!gallerySlides.length) {
            galleryCaption.hidden = true;
            galleryCaption.textContent = '';
            return;
        }
        const slide = gallerySlides[activeSlideIndex];
        const pos = `${activeSlideIndex + 1} / ${gallerySlides.length}`;
        const roleLabel = slide?.role === 'main' ? 'Main product photo' : (slide?.label || 'Gallery detail');
        galleryCaption.hidden = false;
        galleryCaption.textContent = `${pos} · ${roleLabel}`;
    };

    const applySku = ({ sku, partial }) => {
        if (!sku) {
            if (titleEl) titleEl.textContent = data.title || '';
            if (breadcrumbEl) breadcrumbEl.textContent = data.title || '';
            if (priceEl) priceEl.textContent = formatPriceRange();
            if (shortEl) shortEl.textContent = data.shortDescription || shortEl.textContent;
            if (skuNoteEl) {
                skuNoteEl.hidden = true;
                skuNoteEl.textContent = '';
            }
            gallerySlides = buildGallerySlides(null);
            activeSlideIndex = 0;
            setMainImage(gallerySlides[0] || null);
            renderGalleryThumbs();
            updateGalleryCaption();
            return;
        }

        if (titleEl) titleEl.textContent = sku.name || data.title;
        if (breadcrumbEl) breadcrumbEl.textContent = sku.name || data.title;
        if (priceEl) {
            priceEl.textContent = sku.price !== null && !partial
                ? formatMoney(sku.price)
                : (sku.price !== null ? `From ${formatMoney(sku.price)}` : formatPriceRange());
        }
        if (shortEl) {
            shortEl.textContent = sku.shortDescription || data.shortDescription || shortEl.textContent;
        }
        if (longEl && (sku.longDescription || data.longDescription)) {
            longEl.innerHTML = (sku.longDescription || data.longDescription || '')
                .replace(/\n/g, '<br>');
        }
        if (skuNoteEl) {
            skuNoteEl.hidden = false;
            const matchNote = partial
                ? 'Showing the closest sellable SKU for your current selection — choose all options for an exact match.'
                : 'Exact sellable SKU — main and gallery photos belong to this product.';
            const stockNote = sku.inStock ? '' : ' Out of stock in inventory.';
            skuNoteEl.textContent = `${matchNote}${stockNote}`;
        }

        gallerySlides = buildGallerySlides(sku);
        activeSlideIndex = 0;
        setMainImage(gallerySlides[0] || null);
        renderGalleryThumbs();
        updateGalleryCaption();
    };

    const syncActiveControls = () => {
        swatchButtons.forEach((btn) => {
            const groupId = colourGroupId;
            const optionId = Number(btn.dataset.optionId);
            btn.classList.toggle('is-active', groupId && selection.get(groupId) === optionId);
        });
        optionButtons.forEach((btn) => {
            const groupEl = btn.closest('[data-rfm-ecom-preview-variant]');
            const groupId = Number(groupEl?.dataset.groupId);
            const optionId = Number(btn.dataset.optionId);
            btn.classList.toggle('is-active', selection.get(groupId) === optionId);
        });
    };

    const refreshPreview = () => {
        syncActiveControls();
        if (!selectionComplete()) {
            applySku({ sku: null, partial: false });
            return;
        }
        const { sku, partial } = findBestSku();
        applySku({ sku, partial });
    };

    const setSelection = (groupId, optionId) => {
        if (!groupId || !optionId) return;
        selection.set(Number(groupId), Number(optionId));
        refreshPreview();
    };

    const openPreview = () => {
        overlay.hidden = false;
        overlay.removeAttribute('aria-hidden');
        document.body.classList.add('rfm-ecom-preview-open');
        selection.clear();

        swatchButtons.forEach((btn) => btn.classList.remove('is-active'));
        optionButtons.forEach((btn) => btn.classList.remove('is-active'));

        if (colourGroupId && swatchButtons.length) {
            const firstSwatch = swatchButtons[0];
            firstSwatch.classList.add('is-active');
            selection.set(colourGroupId, Number(firstSwatch.dataset.optionId));
        }

        (data.variants || []).forEach((group) => {
            const firstBtn = overlay.querySelector(
                `[data-rfm-ecom-preview-variant][data-group-id="${group.id}"] [data-rfm-ecom-preview-option]`,
            );
            if (firstBtn) {
                firstBtn.classList.add('is-active');
                selection.set(Number(group.id), Number(firstBtn.dataset.optionId));
            }
        });

        refreshPreview();
    };

    const closePreview = () => {
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('rfm-ecom-preview-open');
    };

    root.querySelectorAll('[data-rfm-ecom-preview-open]').forEach((btn) => {
        btn.addEventListener('click', openPreview);
    });

    overlay.querySelectorAll('[data-rfm-ecom-preview-close]').forEach((btn) => {
        btn.addEventListener('click', closePreview);
    });

    swatchButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!colourGroupId) return;
            swatchButtons.forEach((other) => other.classList.remove('is-active'));
            btn.classList.add('is-active');
            setSelection(colourGroupId, Number(btn.dataset.optionId));
        });
    });

    optionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const groupEl = btn.closest('[data-rfm-ecom-preview-variant]');
            const groupId = Number(groupEl?.dataset.groupId);
            if (!groupId) return;
            groupEl.querySelectorAll('[data-rfm-ecom-preview-option]').forEach((other) => {
                other.classList.remove('is-active');
            });
            btn.classList.add('is-active');
            setSelection(groupId, Number(btn.dataset.optionId));
        });
    });

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closePreview();
            return;
        }
        if (gallerySlides.length < 2) return;
        if (event.key === 'ArrowRight') {
            activeSlideIndex = (activeSlideIndex + 1) % gallerySlides.length;
            setMainImage(gallerySlides[activeSlideIndex]);
            renderGalleryThumbs();
            updateGalleryCaption();
        }
        if (event.key === 'ArrowLeft') {
            activeSlideIndex = (activeSlideIndex - 1 + gallerySlides.length) % gallerySlides.length;
            setMainImage(gallerySlides[activeSlideIndex]);
            renderGalleryThumbs();
            updateGalleryCaption();
        }
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Create sellable SKUs from variant values (per chip or after adding a value)
// ─────────────────────────────────────────────────────────────────────────────
const confirmCreateSellableSkus = (count, optionLabel = null) => {
    if (count <= 0) {
        return false;
    }
    if (optionLabel) {
        return window.confirm(
            count === 1
                ? `Create 1 draft sellable SKU for "${optionLabel}"? You can add barcode, price and photo in the SKU list.`
                : `Create ${count} draft sellable SKUs for "${optionLabel}" (all combinations with this value)? You can add barcode, price and photo in the SKU list.`,
        );
    }
    return window.confirm(
        count === 1
            ? 'Create 1 draft sellable SKU for the missing variant combination?'
            : `Create ${count} draft sellable SKUs for the missing variant combinations?`,
    );
};

const submitCreateSkusForOption = async (url, csrf, count, label, skipConfirm = false) => {
    if (!skipConfirm && !confirmCreateSellableSkus(count, label)) {
        return false;
    }
    const formData = new FormData();
    formData.append('_token', csrf);
    const response = await fetch(url, {
        method: 'POST',
        body: formData,
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        redirect: 'follow',
    });
    if (response.ok || response.redirected) {
        window.location.assign(response.url || window.location.href);
        return true;
    }
    return false;
};

const initVariantOptionCreateSkus = (root) => {
    root.querySelectorAll('[data-rfm-create-skus-for-option]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const count = Number(form.dataset.rfmMissingCount) || Number(form.dataset.rfmMissing) || 0;
            if (!confirmCreateSellableSkus(count)) {
                event.preventDefault();
                return;
            }
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-busy');
            }
        });
    });
};

// Variant model: comma / Enter chip entry — silent save, batch create sellable SKUs when ready
const initVariantModelChips = (root, csrf, showToast) => {
    const bulkUrl = root.dataset.rfmVariantOptionsBulkUrl;
    const createNewSkusUrl = root.dataset.rfmCreateNewSkusUrl;
    const destroyUrlTemplate = root.dataset.rfmVariantOptionsDestroyUrlTemplate;
    if (!bulkUrl) return;

    const createBar = root.querySelector('[data-rfm-variant-create-bar]');
    const createSummary = root.querySelector('[data-rfm-variant-create-summary]');
    const createBtn = root.querySelector('[data-rfm-create-new-skus]');

    const normalizeChipLabel = (raw) => String(raw || '').replace(/\s+/g, ' ').trim();
    const buildDestroyUrl = (optionId) => String(destroyUrlTemplate || '').replace(/\/0(?!\d)/, `/${optionId}`);

    const chipLabelsInField = (field) => Array.from(field.querySelectorAll('[data-rfm-vchip]'))
        .map((chip) => chip.dataset.chipLabel || chip.querySelector('.rfm-vchip-label')?.textContent || '')
        .map(normalizeChipLabel)
        .filter(Boolean);

    const pendingOptionIds = () => [...root.querySelectorAll('[data-rfm-vchip]')]
        .filter((chip) => Number(chip.dataset.rfmMissing) > 0)
        .map((chip) => Number(chip.dataset.optionId))
        .filter((id) => id > 0);

    const newValueCount = () => root.querySelectorAll('[data-rfm-vchip][data-rfm-new-option="1"]').length;

    const updateCreateBar = () => {
        if (!createBar || !createBtn) return;

        const pendingIds = pendingOptionIds();
        const missingTotal = pendingIds.reduce((sum, id) => {
            const chip = root.querySelector(`[data-rfm-vchip][data-option-id="${id}"]`);
            return sum + (Number(chip?.dataset.rfmMissing) || 0);
        }, 0);

        const hasWork = pendingIds.length > 0 && missingTotal > 0;
        createBar.hidden = !hasWork;
        createBtn.disabled = !hasWork;

        if (createSummary && hasWork) {
            const newCount = newValueCount();
            const newPart = newCount > 0
                ? `${newCount} new value${newCount === 1 ? '' : 's'}`
                : `${pendingIds.length} value${pendingIds.length === 1 ? '' : 's'}`;
            const skuPart = `${missingTotal} sellable product${missingTotal === 1 ? '' : 's'}`;
            createSummary.textContent = `${newPart} · ${skuPart} ready to create with family name, price and details.`;
        }
    };

    const markChipReady = (optionId) => {
        const chip = root.querySelector(`[data-rfm-vchip][data-option-id="${optionId}"]`);
        if (!chip) return;
        chip.classList.remove('needs-sku');
        chip.classList.add('is-ready');
        chip.dataset.rfmMissing = '0';
        chip.removeAttribute('data-rfm-new-option');
        const pending = chip.querySelector('.rfm-vchip-pending');
        if (pending) {
            pending.className = 'rfm-vchip-ready';
            pending.title = 'Sellable SKU exists for this value';
            pending.textContent = 'Ready';
        }
    };

    const refreshFamilySkuList = async () => {
        try {
            const response = await fetch(window.location.href, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshList = doc.querySelector('[data-rfm-list]');
            const currentList = root.querySelector('[data-rfm-list]');
            if (freshList && currentList) {
                currentList.innerHTML = freshList.innerHTML;
            }
            const freshRefresh = doc.querySelector('[data-rfm-refresh-skus-form]');
            const currentRefresh = root.querySelector('[data-rfm-refresh-skus-form]');
            if (freshRefresh && currentRefresh?.parentElement) {
                currentRefresh.parentElement.innerHTML = freshRefresh.parentElement.innerHTML;
                initVariantRefreshSkus(root);
            }
        } catch {
            // Non-blocking: chips still update if list refresh fails.
        }
    };

    const ensureOptionInSelects = (groupId, option) => {
        document.querySelectorAll(`[data-rfm-variant-axis][data-group-id="${groupId}"] [data-rfm-variant-select]`).forEach((select) => {
            let opt = select.querySelector(`option[value="${option.id}"]`);
            if (!opt) {
                opt = document.createElement('option');
                opt.value = String(option.id);
                opt.textContent = option.label;
                select.append(opt);
            } else {
                opt.textContent = option.label;
            }
        });
    };

    const removeOptionEverywhere = (optionId) => {
        root.querySelectorAll(`[data-rfm-vchip][data-option-id="${optionId}"]`).forEach((chip) => chip.remove());
        root.querySelectorAll(`[data-rfm-manage-row][data-option-id="${optionId}"]`).forEach((row) => row.remove());
        root.querySelectorAll(`[data-rfm-variant-select] option[value="${optionId}"]`).forEach((option) => {
            const select = option.closest('select');
            const wasSelected = select?.value === String(optionId);
            option.remove();
            if (wasSelected && select) {
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        root.querySelectorAll('[data-rfm-manage-empty]').forEach((empty) => {
            const source = empty.closest('[data-rfm-manage-source]');
            const list = source?.querySelector('[data-rfm-manage-list]');
            if (list) empty.hidden = list.children.length > 0;
        });
        updateCreateBar();
    };

    const buildPersistedChip = (option, sellable, isNew = true) => {
        const chip = document.createElement('span');
        const missing = Number(sellable?.missing) || 0;
        chip.className = `rfm-vchip ${missing > 0 ? 'needs-sku' : 'is-ready'}`;
        chip.dataset.rfmVchip = '';
        chip.dataset.optionId = String(option.id);
        chip.dataset.chipLabel = option.label;
        chip.dataset.rfmMissing = String(missing);
        if (isNew && missing > 0) {
            chip.dataset.rfmNewOption = '1';
        }

        const labelEl = document.createElement('span');
        labelEl.className = 'rfm-vchip-label';
        labelEl.textContent = option.label;
        chip.append(labelEl);

        const status = document.createElement('span');
        status.className = missing > 0 ? 'rfm-vchip-pending' : 'rfm-vchip-ready';
        status.title = missing > 0
            ? 'Sellable SKU not created yet'
            : 'Sellable SKU exists for this value';
        status.textContent = missing > 0 ? 'Pending' : 'Ready';
        chip.append(status);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'rfm-vchip-delete';
        deleteBtn.dataset.rfmVchipDelete = '';
        deleteBtn.setAttribute('aria-label', `Remove ${option.label}`);
        deleteBtn.innerHTML = '&times;';
        chip.append(deleteBtn);

        return chip;
    };

    const buildSavingChip = (label) => {
        const chip = document.createElement('span');
        chip.className = 'rfm-vchip is-pending is-saving';
        chip.dataset.rfmVchipSaving = label;
        chip.dataset.chipLabel = label;
        const labelEl = document.createElement('span');
        labelEl.className = 'rfm-vchip-label';
        labelEl.textContent = label;
        chip.append(labelEl);
        return chip;
    };

    const persistLabels = async (field, labels) => {
        const groupId = field.dataset.groupId;
        const box = field.querySelector('.rfm-variant-chip-box');
        const input = field.querySelector('[data-rfm-variant-chip-input]');
        if (!groupId || !box || !labels.length) return;

        const existing = new Set(chipLabelsInField(field).map((l) => l.toLowerCase()));
        const toAdd = labels.filter((label) => !existing.has(label.toLowerCase()));
        if (!toAdd.length) return;

        const savingChips = toAdd.map((label) => {
            const chip = buildSavingChip(label);
            box.insertBefore(chip, input);
            return chip;
        });

        const formData = new FormData();
        formData.append('_token', csrf);
        formData.append('product_variant_group_id', groupId);
        toAdd.forEach((label, index) => formData.append(`labels[${index}]`, label));

        try {
            const response = await fetch(bulkUrl, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.message || 'Could not save values.');
            }

            savingChips.forEach((chip) => chip.remove());

            (data.created || []).forEach((row) => {
                const option = row.option;
                const sellable = row.sellable;
                if (!option) return;
                box.insertBefore(buildPersistedChip(option, sellable, true), input);
                ensureOptionInSelects(groupId, option);
            });

            updateCreateBar();
        } catch (err) {
            savingChips.forEach((chip) => chip.remove());
            showToast(err.message || 'Could not save values.', true);
        }
    };

    const consumeChipInput = (input, includePending = false) => {
        const raw = input.value || '';
        if (!raw.trim()) return;

        const field = input.closest('[data-rfm-variant-chip-field]');
        if (!field) return;

        const tags = (value) => String(value || '').split(',').map((part) => part.trim()).filter(Boolean);

        if (includePending) {
            const labelList = tags(raw);
            input.value = '';
            if (labelList.length) persistLabels(field, labelList);
            return;
        }

        if (!raw.includes(',')) return;

        const endsWithComma = /,\s*$/.test(raw);
        const parts = raw.split(',');
        const pending = endsWithComma ? '' : parts.pop();
        const labelList = parts.map(normalizeChipLabel).filter(Boolean);
        input.value = pending || '';

        if (labelList.length) persistLabels(field, labelList);
    };

    if (createBtn && createNewSkusUrl) {
        createBtn.addEventListener('click', async () => {
            const optionIds = pendingOptionIds();
            if (!optionIds.length) return;

            createBtn.disabled = true;
            createBtn.classList.add('is-busy');
            const originalLabel = createBtn.textContent;
            createBtn.textContent = 'Creating…';

            const formData = new FormData();
            formData.append('_token', csrf);
            optionIds.forEach((id, index) => formData.append(`option_ids[${index}]`, String(id)));

            try {
                const response = await fetch(createNewSkusUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Could not create sellable products.');
                }

                optionIds.forEach((id) => markChipReady(id));

                if (data.variant_option_sellable) {
                    Object.entries(data.variant_option_sellable).forEach(([id, stats]) => {
                        const chip = root.querySelector(`[data-rfm-vchip][data-option-id="${id}"]`);
                        if (chip && stats.missing > 0) {
                            chip.dataset.rfmMissing = String(stats.missing);
                            chip.classList.add('needs-sku');
                            chip.classList.remove('is-ready');
                        }
                    });
                }

                updateCreateBar();
                await refreshFamilySkuList();

                showToast(data.message || 'Sellable products created.');

                const skusWorkspace = document.getElementById('rfm-skus-workspace');
                if (skusWorkspace) {
                    skusWorkspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } catch (err) {
                showToast(err.message || 'Could not create sellable products.', true);
            } finally {
                createBtn.classList.remove('is-busy');
                createBtn.textContent = originalLabel;
                updateCreateBar();
            }
        });
    }

    root.addEventListener('input', (event) => {
        const input = event.target.closest('[data-rfm-variant-chip-input]');
        if (input) consumeChipInput(input);
    });

    root.addEventListener('keydown', (event) => {
        const input = event.target.closest('[data-rfm-variant-chip-input]');
        if (!input || event.key !== 'Enter') return;
        event.preventDefault();
        consumeChipInput(input, true);
    });

    root.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-rfm-vchip-delete]');
        if (!btn) return;
        event.preventDefault();
        event.stopPropagation();

        const chip = btn.closest('[data-rfm-vchip]');
        const optionId = chip?.dataset.optionId;
        const label = chip?.dataset.chipLabel || chip?.querySelector('.rfm-vchip-label')?.textContent || 'this value';
        if (!optionId || !destroyUrlTemplate) return;

        if (!window.confirm(`Remove "${label}" from this variant group?`)) return;

        btn.disabled = true;
        chip.classList.add('is-saving');

        const formData = new FormData();
        formData.append('_token', csrf);
        formData.append('_method', 'DELETE');

        try {
            const response = await fetch(buildDestroyUrl(optionId), {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const firstError = Object.values(data.errors || {}).flat()[0];
                throw new Error(firstError || data.message || 'Could not remove value.');
            }

            removeOptionEverywhere(optionId);
            showToast(data.message || 'Variant value removed.');
        } catch (err) {
            chip.classList.remove('is-saving');
            btn.disabled = false;
            showToast(err.message || 'Could not remove value.', true);
        }
    });

    root.addEventListener('rfm-variant-option-added', (event) => {
        const { groupId, option, sellable } = event.detail || {};
        if (!groupId || !option) return;

        const field = root.querySelector(`[data-rfm-variant-chip-field][data-group-id="${groupId}"]`);
        const box = field?.querySelector('.rfm-variant-chip-box');
        const input = field?.querySelector('[data-rfm-variant-chip-input]');
        if (!box || !input || box.querySelector(`[data-rfm-vchip][data-option-id="${option.id}"]`)) {
            updateCreateBar();
            return;
        }

        box.insertBefore(buildPersistedChip(option, sellable, true), input);
        updateCreateBar();
    });

    updateCreateBar();
};

// ─────────────────────────────────────────────────────────────────────────────
// Refresh sellable products: confirm with the missing count before submitting
// ─────────────────────────────────────────────────────────────────────────────
const initVariantRefreshSkus = (root) => {
    const form = root.querySelector('[data-rfm-refresh-skus-form]');
    if (!form) return;
    form.addEventListener('submit', (event) => {
        const count = Number(form.dataset.rfmMissingCount) || 0;
        if (!confirmCreateSellableSkus(count)) {
            event.preventDefault();
            return;
        }
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('is-busy');
        }
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Add sellable SKU: live preview of generated name + auto SKU
// ─────────────────────────────────────────────────────────────────────────────
const initAddSkuPreview = (root) => {
    const form = root.querySelector('[data-rfm-add-sku-form]');
    if (!form) return;

    const familyName = form.dataset.rfmFamilyName || '';
    const skuPrefix = form.dataset.rfmSkuPrefix || '';
    const hasSkus = form.dataset.rfmHasSkus === '1';
    const nameOut = form.querySelector('[data-rfm-preview-name]');
    const skuOut = form.querySelector('[data-rfm-preview-sku]');
    const hintOut = form.querySelector('[data-rfm-preview-hint]');
    const nameOverride = form.querySelector('[data-rfm-name-override]');
    const skuOverride = form.querySelector('[data-rfm-sku-override]');
    const selects = form.querySelectorAll('[data-rfm-variant-select]');
    if (!nameOut || !skuOut) return;

    const buildGeneratedName = () => {
        const labels = [];
        let missing = 0;
        selects.forEach((select) => {
            const option = select.options[select.selectedIndex];
            if (!option || !option.value) {
                missing += 1;
                return;
            }
            labels.push((option.textContent || '').trim());
        });
        return { name: labels.length ? `${familyName} - ${labels.join(' - ')}` : familyName, missing };
    };

    const renderSkuPreview = () => {
        const override = (skuOverride?.value || '').trim();
        if (override) {
            skuOut.innerHTML = '';
            skuOut.append(document.createTextNode(override));
            const tag = document.createElement('em');
            tag.textContent = '(custom)';
            skuOut.append(' ', tag);
            return;
        }
        if (!skuPrefix) {
            skuOut.innerHTML = '';
            skuOut.append(document.createTextNode('Auto'));
            const tag = document.createElement('em');
            tag.textContent = '(no prefix yet)';
            skuOut.append(' ', tag);
            return;
        }
        const text = `${skuPrefix}?`;
        skuOut.innerHTML = '';
        skuOut.append(document.createTextNode(text));
        const tag = document.createElement('em');
        tag.textContent = '(auto)';
        skuOut.append(' ', tag);
    };

    const renderNamePreview = () => {
        const override = (nameOverride?.value || '').trim();
        if (override) {
            nameOut.textContent = override;
            nameOut.classList.add('is-custom');
            if (hintOut) hintOut.textContent = 'Using your custom product name override.';
            return;
        }
        nameOut.classList.remove('is-custom');
        const { name, missing } = buildGeneratedName();
        nameOut.textContent = name;
        if (hintOut) {
            hintOut.textContent = missing > 0
                ? `Pick a value in ${missing} more variant${missing === 1 ? '' : 's'} to complete the name.`
                : 'All variants picked — ready to create.';
        }
    };

    const renderAll = () => {
        renderNamePreview();
        renderSkuPreview();
    };

    selects.forEach((select) => select.addEventListener('change', renderAll));
    nameOverride?.addEventListener('input', renderNamePreview);
    skuOverride?.addEventListener('input', renderSkuPreview);

    // Re-render whenever the inline variant manager mutates a dropdown.
    root.addEventListener('change', (event) => {
        if (event.target && event.target.matches('[data-rfm-variant-select]')) renderAll();
    });

    renderAll();
    form.__rfmRenderPreview = renderAll;
};

// ─────────────────────────────────────────────────────────────────────────────
// Add sellable SKU: single toggle to switch between "create blank" and
// "duplicate an existing SKU instead" (replaces the old Method + Source pair).
// ─────────────────────────────────────────────────────────────────────────────
const initAddSkuDuplicateToggle = (root) => {
    const form = root.querySelector('[data-rfm-add-sku-form]');
    if (!form) return;

    const toggleBtn = form.querySelector('[data-rfm-duplicate-toggle]');
    const toggleLabel = form.querySelector('[data-rfm-duplicate-toggle-label]');
    const panel = form.querySelector('[data-rfm-duplicate-panel]');
    const modeInput = form.querySelector('[data-rfm-add-sku-mode]');
    if (!toggleBtn || !panel || !modeInput) return;

    const open = panel.hidden === false;
    if (open) modeInput.value = 'duplicate';

    toggleBtn.addEventListener('click', () => {
        const willOpen = panel.hidden;
        panel.hidden = !willOpen;
        modeInput.value = willOpen ? 'duplicate' : 'fresh';
        toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        toggleBtn.classList.toggle('is-active', willOpen);
        if (toggleLabel) {
            toggleLabel.textContent = willOpen
                ? 'Cancel duplicate, create blank instead'
                : 'Duplicate an existing SKU instead';
        }
    });
};

// ─────────────────────────────────────────────────────────────────────────────
// Variant-option manager (Add sellable SKU) — mobile bottom-sheet modal
// ─────────────────────────────────────────────────────────────────────────────
const initVariantOptionManagers = (root, csrf, showToast) => {
    const picker = root.querySelector('[data-rfm-variant-picker]');
    const modal = root.querySelector('[data-rfm-variant-manage-modal]');
    if (!picker || !modal) return;

    const createUrl = picker.dataset.rfmVariantOptionsCreateUrl;
    const updateUrlTemplate = picker.dataset.rfmVariantOptionsUpdateUrlTemplate;
    const destroyUrlTemplate = picker.dataset.rfmVariantOptionsDestroyUrlTemplate;
    if (!createUrl || !updateUrlTemplate || !destroyUrlTemplate) return;

    const modalList = modal.querySelector('[data-rfm-manage-list]');
    const modalEmpty = modal.querySelector('[data-rfm-manage-empty]');
    const modalAddInput = modal.querySelector('[data-rfm-manage-add-input]');
    const modalAddBtn = modal.querySelector('[data-rfm-manage-add]');
    const modalTitle = modal.querySelector('[data-rfm-variant-manage-title]');
    const closeTriggers = modal.querySelectorAll('[data-rfm-variant-manage-close]');
    if (!modalList || !modalAddInput || !modalAddBtn || !modalTitle) return;

    const buildUrl = (template, optionId) => template.replace(/\/0(?!\d)/, `/${optionId}`);

    const errorMessageFromResponse = (data, fallback) => {
        if (!data) return fallback;
        if (data.message && (!data.errors || Object.keys(data.errors).length === 0)) return data.message;
        const firstError = Object.values(data.errors || {}).flat()[0];
        return firstError || data.message || fallback;
    };

    const callJson = async (url, method, payload) => {
        const formData = new FormData();
        formData.append('_token', csrf);
        if (method !== 'POST') formData.append('_method', method);
        Object.entries(payload || {}).forEach(([key, value]) => {
            if (value !== undefined && value !== null) formData.append(key, value);
        });

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(errorMessageFromResponse(data, 'Save failed.'));
        }
        return data;
    };

    let activeAxis = null;
    let activeGroupId = '';
    let activeGroupName = 'value';
    let activeSelect = null;
    let activeSourceList = null;
    let activeSourceEmpty = null;
    let lastFocused = null;

    const ensureOptionInSelect = (select, optionId, label) => {
        let opt = select.querySelector(`option[value="${optionId}"]`);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = String(optionId);
            opt.textContent = label;
            select.append(opt);
        } else {
            opt.textContent = label;
        }
        return opt;
    };

    const renameOptionEverywhere = (groupId, optionId, label) => {
        document.querySelectorAll(`[data-rfm-variant-axis][data-group-id="${groupId}"] [data-rfm-variant-select]`).forEach((select) => {
            const opt = select.querySelector(`option[value="${optionId}"]`);
            if (opt) opt.textContent = label;
        });
    };

    const removeOptionEverywhere = (groupId, optionId) => {
        document.querySelectorAll(`[data-rfm-variant-axis][data-group-id="${groupId}"] [data-rfm-variant-select]`).forEach((select) => {
            const opt = select.querySelector(`option[value="${optionId}"]`);
            if (opt) {
                if (select.value === String(optionId)) select.value = '';
                opt.remove();
            }
        });
    };

    const buildManageRow = (option) => {
        const li = document.createElement('li');
        li.className = 'rfm-variant-manage-row';
        li.dataset.rfmManageRow = '';
        li.dataset.optionId = String(option.id);
        li.innerHTML = `
            <span class="rfm-variant-manage-label" data-rfm-manage-label></span>
            <input type="text" class="rfm-variant-manage-edit-input" data-rfm-manage-edit-input hidden>
            <div class="rfm-variant-manage-row-actions">
                <button type="button" class="rfm-variant-manage-btn" data-rfm-manage-edit>Rename</button>
                <button type="button" class="rfm-variant-manage-btn" data-rfm-manage-save hidden>Save</button>
                <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-ghost" data-rfm-manage-cancel hidden>Cancel</button>
                <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-danger" data-rfm-manage-delete>Remove</button>
            </div>
        `;
        li.querySelector('[data-rfm-manage-label]').textContent = option.label;
        li.querySelector('[data-rfm-manage-edit-input]').value = option.label;
        return li;
    };

    const syncEmptyState = () => {
        const hasRows = modalList.children.length > 0;
        if (modalEmpty) modalEmpty.hidden = hasRows;
        if (activeSourceEmpty) activeSourceEmpty.hidden = hasRows;
    };

    const returnRowsToSource = () => {
        if (!activeSourceList) return;
        while (modalList.firstChild) {
            activeSourceList.appendChild(modalList.firstChild);
        }
    };

    const openModal = (axis) => {
        const source = axis.querySelector('[data-rfm-manage-source]');
        if (!source) return;

        activeAxis = axis;
        activeGroupId = axis.dataset.groupId || '';
        activeGroupName = axis.dataset.groupName || 'value';
        activeSelect = axis.querySelector('[data-rfm-variant-select]');
        activeSourceList = source.querySelector('[data-rfm-manage-list]');
        activeSourceEmpty = source.querySelector('[data-rfm-manage-empty]');
        const sourceAddInput = source.querySelector('[data-rfm-manage-add-input]');

        if (!activeSourceList || !activeSelect) return;

        lastFocused = document.activeElement;
        returnRowsToSource();

        while (activeSourceList.firstChild) {
            modalList.appendChild(activeSourceList.firstChild);
        }

        modalTitle.textContent = activeGroupName;
        modalAddInput.placeholder = sourceAddInput?.placeholder || `Add new ${activeGroupName.toLowerCase()} value`;
        modalAddInput.value = '';

        axis.classList.add('is-managing');
        modal.hidden = false;
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('rfm-variant-manage-open');
        syncEmptyState();
        window.setTimeout(() => modalAddInput.focus(), 50);
    };

    const closeModal = () => {
        if (!activeAxis) return;

        returnRowsToSource();
        activeAxis.classList.remove('is-managing');
        activeAxis = null;
        activeGroupId = '';
        activeSelect = null;
        activeSourceList = null;
        activeSourceEmpty = null;

        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('rfm-variant-manage-open');

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
        lastFocused = null;
    };

    const handleAdd = async () => {
        if (!activeSelect || !activeSourceList) return;

        const label = modalAddInput.value.trim();
        if (!label) {
            modalAddInput.focus();
            return;
        }

        modalAddBtn.disabled = true;
        try {
            const data = await callJson(createUrl, 'POST', {
                product_variant_group_id: activeGroupId,
                label,
            });
            const newOpt = data.option;
            if (!newOpt) throw new Error('Could not create option.');

            ensureOptionInSelect(activeSelect, newOpt.id, newOpt.label);
            document.querySelectorAll(`[data-rfm-variant-axis][data-group-id="${activeGroupId}"] [data-rfm-variant-select]`).forEach((s) => {
                if (s !== activeSelect) ensureOptionInSelect(s, newOpt.id, newOpt.label);
            });
            activeSelect.value = String(newOpt.id);
            activeSelect.dispatchEvent(new Event('change', { bubbles: true }));

            const row = buildManageRow(newOpt);
            modalList.append(row);
            activeSourceList.append(row.cloneNode(true));

            modalAddInput.value = '';
            modalAddInput.focus();
            syncEmptyState();
            root.dispatchEvent(new CustomEvent('rfm-variant-option-added', {
                bubbles: true,
                detail: { groupId: activeGroupId, option: newOpt, sellable: data.sellable },
            }));
        } catch (err) {
            showToast(err.message || 'Could not add value.', true);
        } finally {
            modalAddBtn.disabled = false;
        }
    };

    modalAddBtn.addEventListener('click', handleAdd);
    modalAddInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            handleAdd();
        }
    });

    modalList.addEventListener('click', async (event) => {
        if (!activeSourceList) return;

        const row = event.target.closest('[data-rfm-manage-row]');
        if (!row) return;
        const optionId = row.dataset.optionId;
        if (!optionId) return;

        const labelEl = row.querySelector('[data-rfm-manage-label]');
        const inputEl = row.querySelector('[data-rfm-manage-edit-input]');
        const editBtn = row.querySelector('[data-rfm-manage-edit]');
        const saveBtn = row.querySelector('[data-rfm-manage-save]');
        const cancelBtn = row.querySelector('[data-rfm-manage-cancel]');
        const deleteBtn = row.querySelector('[data-rfm-manage-delete]');
        const sourceRow = activeSourceList.querySelector(`[data-option-id="${optionId}"]`);

        const syncSourceRowFromModal = () => {
            if (!sourceRow) return;
            sourceRow.querySelector('[data-rfm-manage-label]').textContent = labelEl.textContent;
            sourceRow.querySelector('[data-rfm-manage-edit-input]').value = inputEl.value;
        };

        if (event.target === editBtn) {
            inputEl.hidden = false;
            labelEl.hidden = true;
            editBtn.hidden = true;
            deleteBtn.hidden = true;
            saveBtn.hidden = false;
            cancelBtn.hidden = false;
            inputEl.focus();
            inputEl.select();
            return;
        }

        if (event.target === cancelBtn) {
            inputEl.value = labelEl.textContent || '';
            inputEl.hidden = true;
            labelEl.hidden = false;
            editBtn.hidden = false;
            deleteBtn.hidden = false;
            saveBtn.hidden = true;
            cancelBtn.hidden = true;
            return;
        }

        if (event.target === saveBtn) {
            const newLabel = inputEl.value.trim();
            if (!newLabel) {
                inputEl.focus();
                return;
            }
            if (newLabel === labelEl.textContent) {
                cancelBtn.click();
                return;
            }
            saveBtn.disabled = true;
            try {
                const data = await callJson(buildUrl(updateUrlTemplate, optionId), 'PATCH', { label: newLabel });
                const updated = data.option;
                labelEl.textContent = updated.label;
                inputEl.value = updated.label;
                inputEl.hidden = true;
                labelEl.hidden = false;
                editBtn.hidden = false;
                deleteBtn.hidden = false;
                saveBtn.hidden = true;
                cancelBtn.hidden = true;
                syncSourceRowFromModal();
                renameOptionEverywhere(activeGroupId, updated.id, updated.label);
                showToast(data.message || 'Renamed.');
            } catch (err) {
                showToast(err.message || 'Could not rename.', true);
            } finally {
                saveBtn.disabled = false;
            }
            return;
        }

        if (event.target === deleteBtn) {
            const label = labelEl.textContent || 'this value';
            if (!window.confirm(`Remove "${label}" from ${activeGroupName}?`)) return;
            deleteBtn.disabled = true;
            try {
                const data = await callJson(buildUrl(destroyUrlTemplate, optionId), 'DELETE', {});
                row.remove();
                sourceRow?.remove();
                removeOptionEverywhere(activeGroupId, optionId);
                syncEmptyState();
                showToast(data.message || 'Removed.');
            } catch (err) {
                showToast(err.message || 'Could not remove.', true);
                deleteBtn.disabled = false;
            }
        }
    });

    closeTriggers.forEach((btn) => {
        btn.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
    });

    picker.querySelectorAll('[data-rfm-variant-axis]').forEach((axis) => {
        const openBtn = axis.querySelector('[data-rfm-manage-open]');
        if (!openBtn) return;
        openBtn.addEventListener('click', () => openModal(axis));
    });
};

const initInventoryStructureAjax = () => {
    if (window.__lhcInventoryStructureAjax) return;
    window.__lhcInventoryStructureAjax = true;

    const currentRoot = () => document.querySelector('[data-inventory-structure]');

    const showStatus = (message, isError = false) => {
        const status = currentRoot()?.querySelector('[data-inventory-status]');
        if (!status) return;
        status.hidden = false;
        status.textContent = message;
        status.classList.toggle('is-error', isError);
        window.clearTimeout(showStatus.timer);
        showStatus.timer = window.setTimeout(() => {
            status.hidden = true;
            status.textContent = '';
            status.classList.remove('is-error');
        }, isError ? 6500 : 2800);
    };

    const openDetails = () => new Set(
        Array.from(currentRoot()?.querySelectorAll('details[data-inv-details][open]') || [])
            .map((detail) => detail.dataset.invDetails)
            .filter(Boolean),
    );

    const restoreDetails = (keys) => {
        if (!keys?.size) return;
        currentRoot()?.querySelectorAll('details[data-inv-details]').forEach((detail) => {
            detail.open = keys.has(detail.dataset.invDetails);
        });
    };

    const refreshPanel = async (keys, message, isError = false) => {
        const response = await fetch(window.location.href, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Saved, but the screen could not refresh.');
        }

        const html = await response.text();
        const next = new DOMParser()
            .parseFromString(html, 'text/html')
            .querySelector('[data-inventory-structure]');
        const root = currentRoot();

        if (!next || !root) {
            throw new Error('Saved, but the updated inventory panel was not found.');
        }

        root.replaceWith(next);
        restoreDetails(keys);
        showStatus(message, isError);
    };

    const firstError = (data) => Object.values(data.errors || {}).flat()[0];

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-inventory-structure] form');
        if (!form) return;

        event.preventDefault();

        const confirmation = form.dataset.confirm;
        if (confirmation && !window.confirm(confirmation)) {
            return;
        }

        const keys = openDetails();
        form.closest('details[data-inv-details]')?.dataset.invDetails && keys.add(form.closest('details[data-inv-details]').dataset.invDetails);
        const submitButton = form.querySelector('button[type="submit"]');
        const originalLabel = submitButton?.textContent;

        form.classList.add('is-saving');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }
        showStatus('Saving...');

        try {
            const response = await fetch(form.action, {
                method: (form.method || 'POST').toUpperCase(),
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                throw new Error(data.message || firstError(data) || 'Unable to save this change.');
            }

            await refreshPanel(keys, data.message || 'Saved.');
        } catch (error) {
            showStatus(error.message || 'Unable to save this change.', true);
        } finally {
            form.classList.remove('is-saving');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalLabel || 'Save';
            }
        }
    });
};

const initHairIntakeWizard = () => {
    const root = document.querySelector('[data-hew-root]');
    if (!root) return;

    const shell = root.querySelector('[data-hew-shell]');
    const toast = root.querySelector('[data-hew-toast]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const routes = JSON.parse(root.dataset.routes || '{}');
    const activeSessions = [];
    const state = {
        session: JSON.parse(root.dataset.session || 'null'),
        reference: JSON.parse(root.dataset.reference || '{"brands":[],"stores":[],"photo_roles":[]}'),
        selectedVariantId: null,
        variantFilter: 'all',
        selectedCandidateId: null,
        localReview: null,
        capturePhotoFile: null,
        capturePhotoUrl: null,
        variantScopeKey: null,
        variantScopeFilters: { main: '', sub: '', common: '' },
        variantScopeSelectedIds: [],
        variantScopeFamilies: [],
        editingScopeFamilyUid: null,
        selectedFamilyGroupId: 'all',
        busy: false,
    };

    const steps = ['Capture', 'Match', 'Verify', 'Fill', 'Local Review', 'Final Review', 'Publish'];
    const variantAxisPresets = [
        'Length',
        'Colour',
        'Pack count',
        'Bundle count',
        'Piece count',
        'Weight',
        'Texture',
        'Size',
        'Material',
        'Parting',
        'Style note',
        'Other',
    ];
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));

    const showToast = (message, isError = false) => {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        toast.classList.toggle('is-error', isError);
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => {
            toast.hidden = true;
            toast.textContent = '';
            toast.classList.remove('is-error');
        }, isError ? 7000 : 3200);
    };

    const openBarcodeScanner = async (input) => {
        if (!input) return;

        if (!cameraBarcodeSupported()) {
            input.focus();
            showToast('Camera barcode scanning is not supported here. Use your scanner or type the code.');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'hew-scanner';
        overlay.innerHTML = `
            <div class="hew-scanner-panel">
                <h2>Scan barcode</h2>
                <p class="hew-muted">Point the camera at the barcode.</p>
                <video autoplay playsinline muted></video>
                <div class="hew-actions">
                    <button class="hew-btn danger" type="button">Cancel</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        const video = overlay.querySelector('video');
        const cancel = overlay.querySelector('button');

        const session = createCameraBarcodeSession({
            video,
            onDetected: (value) => {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                showToast('Barcode scanned.');
                session.close();
                overlay.remove();
                input.focus();
            },
            onError: (message) => {
                showToast(message, true);
                session.close();
                overlay.remove();
                input.focus();
            },
        });

        const close = () => {
            session.close();
            overlay.remove();
            input.focus();
        };

        cancel.addEventListener('click', close);
        const started = await session.start();
        if (!started) {
            close();
        }
    };

    const api = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed.';
            const error = new Error(message);
            error.data = data;
            throw error;
        }
        return data;
    };

    const sessionUrl = (suffix = '') => `${state.session?.url || ''}${suffix}`;
    const setSession = (session) => {
        state.session = session;
        if (session?.uuid && window.location.href !== session.url) {
            window.history.replaceState({}, '', session.url);
        }
    };

    const setBusy = (busy) => {
        state.busy = busy;
        render();
    };

    const sessionStep = () => Number(state.session?.current_step || 1);

    const renderProgress = () => {
        const current = sessionStep();
        const canNavigate = Boolean(state.session);
        return `<div class="hew-progress">${steps.map((label, index) => {
            const step = index + 1;
            const className = `hew-step-dot ${step === current ? 'is-active' : ''} ${step < current ? 'is-done' : ''}`;
            if (!canNavigate) {
                return `<span class="${className}" title="${step}. ${escapeHtml(label)}"></span>`;
            }

            return `<button class="${className}" type="button" data-hew-action="go-step" data-step="${step}" aria-label="Go to step ${step}: ${escapeHtml(label)}" title="${step}. ${escapeHtml(label)}"><span>${step}</span></button>`;
        }).join('')}<span class="hew-step-label">${current}/${steps.length} ${escapeHtml(steps[current - 1] || '')}</span></div>`;
    };

    const renderHeader = () => {
        const session = state.session;
        const brand = session?.brand_name || '';
        const style = session?.matched_style?.name || session?.style_name_hint || '';
        return `
            <div class="hew-header">
                <div class="hew-header-row">
                    <h1 class="hew-header-title">Intake${brand ? ` · ${escapeHtml(brand)}` : ''}</h1>
                    <div class="hew-header-meta">
                        ${style ? `<span>${escapeHtml(style)}</span>` : ''}
                        ${session ? `<button class="hew-btn" style="padding:0.4rem 0.65rem;font-size:0.75rem;min-height:0" type="button" data-hew-action="save-exit">Exit</button>` : ''}
                    </div>
                </div>
                ${renderProgress()}
            </div>
        `;
    };

    const renderHero = () => '';

    const renderSide = () => {
        const session = state.session;
        if (!session) return '';
        return `
            <aside class="hew-side">
                <h2>Session</h2>
                <div><span class="hew-badge">Brand</span> <strong>${escapeHtml(session.brand_name || 'Not set')}</strong></div>
                <div><span class="hew-badge">Style</span> <strong>${escapeHtml(session.matched_style?.name || session.style_name_hint || 'Not matched')}</strong></div>
                <div><span class="hew-badge">Status</span> <strong>${escapeHtml(session.status)}</strong></div>
                ${session.photo_url ? `<img class="hew-match-photo" src="${escapeHtml(session.photo_url)}" alt="Match photo">` : ''}
                <button class="hew-btn" type="button" data-hew-action="save-exit">Save & exit</button>
            </aside>
        `;
    };

    const brandOptions = (selected = '') => state.reference.brands.map((brand) =>
        `<option value="${brand.id}" ${String(selected) === String(brand.id) ? 'selected' : ''}>${escapeHtml(brand.name)}</option>`
    ).join('');

    const axisOptions = (selected) => variantAxisPresets.map((axis) =>
        `<option value="${escapeHtml(axis)}" ${axis === selected ? 'selected' : ''}>${escapeHtml(axis)}</option>`
    ).join('');

    const renderVariantAxisCard = ({ role, title, defaultAxis, placeholder, examples, helper }) => `
        <section class="hew-axis-card">
            <div class="hew-axis-head">
                <div>
                    <strong>${escapeHtml(title)}</strong>
                    <span>${escapeHtml(helper)}</span>
                </div>
                <label class="hew-axis-select">Axis
                    <select name="obs_${escapeHtml(role)}_axis">
                        ${axisOptions(defaultAxis)}
                    </select>
                </label>
            </div>
            <div class="hew-chip-box" data-hew-chip-field>
                <input type="text" name="obs_${escapeHtml(role)}" data-hew-chip-input placeholder="${escapeHtml(placeholder)}">
            </div>
            <p class="hew-axis-helper">${escapeHtml(examples)}</p>
        </section>
    `;

    const renderEntry = () => `
        ${activeSessions.length ? `
            <div class="hew-card">
                <h2>Resume session</h2>
                <div class="hew-resume-grid">
                    ${activeSessions.map((session) => `
                        <a class="hew-mini-card" href="${escapeHtml(session.url)}" style="display:block;text-decoration:none;color:inherit;min-height:2.875rem">
                            <strong>${escapeHtml(session.style || 'Unmatched')}</strong>
                            <p class="hew-muted" style="margin:0.2rem 0 0">${escapeHtml(session.brand || '?')} · step ${session.current_step}</p>
                        </a>
                    `).join('')}
                </div>
            </div>
        ` : ''}
        <form class="hew-card hew-form-grid" data-hew-step1-form>
            <div class="hew-entry-head">
                <h2>Capture product</h2>
                ${routes.sessions ? `<a class="hew-text-link" href="${escapeHtml(routes.sessions)}">Sessions</a>` : ''}
            </div>

            <div class="hew-capture-area" data-hew-capture-area>
                <div class="hew-capture-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
                <span class="hew-capture-text">Add product photo</span>
                <div class="hew-capture-actions">
                    <button class="hew-btn primary" type="button" data-hew-action="choose-photo-camera">Take photo</button>
                    <button class="hew-btn" type="button" data-hew-action="choose-photo-library">Upload from phone</button>
                    <button class="hew-btn" type="button" data-hew-action="paste-photo">Paste photo</button>
                </div>
                <div class="hew-capture-paste-zone" tabindex="0" contenteditable="true" role="textbox" aria-label="Paste product photo here" data-hew-capture-paste>
                    <strong>Paste copied image here</strong>
                    <span>Tap here, then paste. On desktop use Ctrl+V.</span>
                </div>
                <p class="hew-capture-status" data-hew-capture-status hidden></p>
                <input type="file" name="photo" accept="image/*" hidden data-hew-photo-preview>
            </div>

            <label class="hew-field">Brand
                <select name="brand_catalogue_brand_id" required style="font-size:1.05rem">
                    <option value="">Choose brand</option>
                    ${brandOptions()}
                </select>
            </label>

            <label class="hew-field">Style name hint
                <input type="text" name="style_name_hint" placeholder="What you can read on pack">
            </label>

            <div class="hew-variant-map">
                <div class="hew-variant-map-head">
                    <h3>Observations</h3>
                    <p>Type values and press Enter or comma to add chips</p>
                </div>
                ${renderVariantAxisCard({
                    role: 'main',
                    title: 'Main',
                    defaultAxis: 'Length',
                    placeholder: '20", 46, 82...',
                    examples: '14", 20 inch, 46", 72"',
                    helper: 'Primary shelf choice',
                })}
                ${renderVariantAxisCard({
                    role: 'sub',
                    title: 'Sub',
                    defaultAxis: 'Colour',
                    placeholder: '1, 1B, T1B/30...',
                    examples: '1, 1B, 2, 4, T1B/27',
                    helper: 'Colour or shade',
                })}
                ${renderVariantAxisCard({
                    role: 'common',
                    title: 'Common',
                    defaultAxis: 'Pack count',
                    placeholder: '3X, 100g...',
                    examples: '2X, 3X, 100g, synthetic',
                    helper: 'Shared detail',
                })}
            </div>

            <label class="hew-field">Note
                <textarea name="user_note" rows="2" placeholder="Anything unusual"></textarea>
            </label>

            <div class="hew-bottom-bar">
                <button class="hew-btn primary full" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? 'Saving...' : 'Save for Codex check'}</button>
            </div>
        </form>
    `;

    const latestMatch = () => state.session?.latest_match || null;
    const latestReview = () => state.localReview || state.session?.latest_review || null;

    const matchVariants = (match) => Array.isArray(match?.variants) ? match.variants : [];

    const scopeVariantId = (variant) => Number(variant?.variant_id || variant?.id || 0);

    const scopeKeyForMatch = (match) => {
        const styleId = match?.matched_style?.style_id || '';
        return `${styleId}:${matchVariants(match).map((variant) => scopeVariantId(variant)).join('|')}`;
    };

    const ensureVariantScope = (match) => {
        const key = scopeKeyForMatch(match);
        if (state.variantScopeKey === key) return;

        const observedIds = matchVariants(match)
            .filter((variant) => variant.matches_observation)
            .map((variant) => scopeVariantId(variant))
            .filter(Boolean);

        state.variantScopeKey = key;
        state.variantScopeFilters = { main: '', sub: '', common: '' };
        state.variantScopeSelectedIds = [...new Set(observedIds)];
        state.variantScopeFamilies = [];
        state.editingScopeFamilyUid = null;
    };

    const scopeAxisTitle = (match, axis) => {
        const taxonomy = match?.variant_taxonomy || {};
        return ({
            main: taxonomy.main_axis || 'Main',
            sub: taxonomy.sub_axis || 'Sub',
            common: taxonomy.common_axis || 'Common',
        })[axis] || axis;
    };

    const scopeAxisValues = (match, axis) => {
        const seen = new Set();
        return matchVariants(match).reduce((values, variant) => {
            const value = String(variant?.[axis] || '').trim();
            if (!value || seen.has(value)) return values;
            seen.add(value);
            values.push(value);
            return values;
        }, []);
    };

    const filteredScopeVariants = (match) => matchVariants(match).filter((variant) =>
        ['main', 'sub', 'common'].every((axis) => {
            const filter = state.variantScopeFilters?.[axis] || '';
            return !filter || String(variant?.[axis] || '') === filter;
        })
    );

    const renderScopeFilter = (match, axis) => {
        const title = scopeAxisTitle(match, axis);
        const selected = state.variantScopeFilters?.[axis] || '';
        return `
            <label class="hew-field hew-scope-filter">${escapeHtml(title)}
                <select data-hew-scope-filter data-axis="${escapeHtml(axis)}">
                    <option value="">All ${escapeHtml(title)}</option>
                    ${scopeAxisValues(match, axis).map((value) => `<option value="${escapeHtml(value)}" ${value === selected ? 'selected' : ''}>${escapeHtml(value)}</option>`).join('')}
                </select>
            </label>
        `;
    };

    const cleanScopeLabel = (value) => String(value || '')
        .replace(/^\s*(length|colour|color|bundle|pack count|piece count)\s*:\s*/i, '')
        .trim();

    const scopeFamilyName = (match) => {
        const filters = state.variantScopeFilters || {};
        const parts = ['main', 'common', 'sub']
            .map((axis) => cleanScopeLabel(filters[axis]))
            .filter(Boolean);

        return parts.length ? parts.join(' / ') : `${match?.matched_style?.style_name || 'Selected'} family ${state.variantScopeFamilies.length + 1}`;
    };

    const groupedScopeSkuIds = (excludeUid = null) => new Set(
        (state.variantScopeFamilies || [])
            .filter((family) => !excludeUid || String(family.uid) !== String(excludeUid))
            .flatMap((family) => family.sku_ids || [])
            .map(Number)
            .filter(Boolean)
    );

    const scopeFamilyPayload = () => (state.variantScopeFamilies || []).map((family, index) => ({
        name: family.name || `Family ${index + 1}`,
        scope: family.scope || {},
        sku_ids: (family.sku_ids || []).map(Number).filter(Boolean),
    }));

    const renderVariantScope = (match) => {
        ensureVariantScope(match);

        const variants = matchVariants(match);
        if (!variants.length) {
            return `
                <div class="hew-scope-panel">
                    <p class="hew-muted" style="margin:0">This match has no catalogue variants to scope.</p>
                </div>
            `;
        }

        const filtered = filteredScopeVariants(match);
        const selected = new Set((state.variantScopeSelectedIds || []).map(Number));
        const editingFamily = (state.variantScopeFamilies || [])
            .find((family) => String(family.uid) === String(state.editingScopeFamilyUid));
        const grouped = groupedScopeSkuIds(state.editingScopeFamilyUid);
        const selectedCount = selected.size;
        const observedCount = variants.filter((variant) => variant.matches_observation).length;
        const selectedVisibleCount = filtered.filter((variant) => selected.has(scopeVariantId(variant))).length;
        const availableFilteredCount = filtered.filter((variant) => !grouped.has(scopeVariantId(variant))).length;
        const familyCount = (state.variantScopeFamilies || []).length;
        const selectedVariants = variants.filter((variant) => selected.has(scopeVariantId(variant)));

        return `
            <div class="hew-scope-panel">
                <div class="hew-scope-head">
                    <div>
                        <h3>Build family buckets</h3>
                        <p>Filter one family, select its variants, add it as a bucket, then repeat for the next length or pack.</p>
                    </div>
                    <div class="hew-scope-counts">
                        <span class="hew-badge manual">${familyCount} families</span>
                        <span class="hew-badge manual">${selectedCount} selected</span>
                        <span class="hew-badge">${filtered.length} showing</span>
                        ${observedCount ? `<span class="hew-badge">${observedCount} observed</span>` : ''}
                    </div>
                </div>

                <div class="hew-scope-filters">
                    ${renderScopeFilter(match, 'main')}
                    ${renderScopeFilter(match, 'sub')}
                    ${renderScopeFilter(match, 'common')}
                </div>

                ${editingFamily ? `
                    <div class="hew-scope-editing">
                        <span class="hew-badge manual">Editing family</span>
                        <strong>${escapeHtml(editingFamily.name)}</strong>
                        <button class="hew-btn danger" type="button" data-hew-action="cancel-scope-edit">Cancel family edit</button>
                    </div>
                ` : ''}

                <div class="hew-scope-actions">
                    <button class="hew-btn primary" type="button" data-hew-action="select-scope-visible" ${availableFilteredCount ? '' : 'disabled'}>Select all matching (${availableFilteredCount})</button>
                    <button class="hew-btn" type="button" data-hew-action="deselect-scope-visible" ${selectedVisibleCount ? '' : 'disabled'}>Deselect matching</button>
                    <button class="hew-btn" type="button" data-hew-action="select-scope-observed" ${observedCount ? '' : 'disabled'}>Select observed</button>
                    <button class="hew-btn primary" type="button" data-hew-action="${editingFamily ? 'update-scope-family' : 'add-scope-family'}" ${selectedCount ? '' : 'disabled'}>${editingFamily ? 'Update family' : 'Add selected as family'}</button>
                    <button class="hew-btn danger" type="button" data-hew-action="clear-scope-selection" ${selectedCount ? '' : 'disabled'}>Clear</button>
                </div>

                <p class="hew-muted" style="margin:0">${selectedVisibleCount} of the matching variants are selected. Variants in other families are locked.</p>

                ${selectedVariants.length ? `
                    <div class="hew-selected-sellables">
                        <div class="hew-selected-sellables-head">
                            <div>
                                <strong>Selected sellables</strong>
                                <p>Remove individual variants here. Barcode, price, photos and location are edited in Step 4.</p>
                            </div>
                            <span class="hew-badge manual">${selectedVariants.length}</span>
                        </div>
                        <div class="hew-selected-sellables-list">
                            ${selectedVariants.map((variant) => {
                                const id = scopeVariantId(variant);
                                return `
                                    <div class="hew-selected-sellable">
                                        <div>
                                            <strong>${escapeHtml(variant.display_name || `Variant ${id}`)}</strong>
                                            <span>
                                                ${variant.main ? `<em>${escapeHtml(variant.main)}</em>` : ''}
                                                ${variant.sub ? `<em>${escapeHtml(variant.sub)}</em>` : ''}
                                                ${variant.common ? `<em>${escapeHtml(variant.common)}</em>` : ''}
                                            </span>
                                        </div>
                                        <button class="hew-btn danger" type="button" data-hew-action="remove-scope-selected-variant" data-variant-id="${id}">Remove</button>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                ` : ''}

                ${(state.variantScopeFamilies || []).length ? `
                    <div class="hew-family-bucket-list">
                        ${(state.variantScopeFamilies || []).map((family, index) => `
                            <div class="hew-family-bucket ${String(state.editingScopeFamilyUid) === String(family.uid) ? 'is-editing' : ''}">
                                <div>
                                    <span class="hew-badge">Family ${index + 1}</span>
                                    <strong>${escapeHtml(family.name)}</strong>
                                    <p>${(family.sku_ids || []).length} selected variants</p>
                                </div>
                                <div class="hew-family-bucket-actions">
                                    <button class="hew-btn" type="button" data-hew-action="edit-scope-family" data-family-uid="${escapeHtml(family.uid)}">Edit</button>
                                    <button class="hew-btn danger" type="button" data-hew-action="remove-scope-family" data-family-uid="${escapeHtml(family.uid)}">Delete</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                ` : ''}

                <div class="hew-scope-list">
                    ${filtered.length ? filtered.map((variant) => {
                        const id = scopeVariantId(variant);
                        const checked = selected.has(id);
                        const alreadyGrouped = grouped.has(id);
                        return `
                            <label class="hew-scope-row ${checked ? 'is-selected' : ''} ${alreadyGrouped ? 'is-grouped' : ''}">
                                <input type="checkbox" value="${id}" data-hew-scope-checkbox ${checked ? 'checked' : ''} ${alreadyGrouped ? 'disabled' : ''}>
                                <span class="hew-scope-row-body">
                                    <strong>${escapeHtml(variant.display_name || `Variant ${id}`)}</strong>
                                    <span>
                                        ${variant.main ? `<em>${escapeHtml(variant.main)}</em>` : ''}
                                        ${variant.sub ? `<em>${escapeHtml(variant.sub)}</em>` : ''}
                                        ${variant.common ? `<em>${escapeHtml(variant.common)}</em>` : ''}
                                    </span>
                                </span>
                                ${alreadyGrouped ? '<span class="hew-badge">added</span>' : ''}
                                ${variant.matches_observation ? '<span class="hew-badge manual">observed</span>' : ''}
                            </label>
                        `;
                    }).join('') : '<div class="hew-mini-card"><p class="hew-muted" style="margin:0">No variants match these filters.</p></div>'}
                </div>
            </div>
        `;
    };

    const renderScopeFamilyEditPage = (match, editingFamily) => {
        ensureVariantScope(match);

        const variants = matchVariants(match);
        const filtered = filteredScopeVariants(match);
        const selected = new Set((state.variantScopeSelectedIds || []).map(Number));
        const grouped = groupedScopeSkuIds(editingFamily?.uid);
        const selectedCount = selected.size;
        const observedCount = variants.filter((variant) => variant.matches_observation).length;
        const selectedVisibleCount = filtered.filter((variant) => selected.has(scopeVariantId(variant))).length;
        const availableFilteredCount = filtered.filter((variant) => !grouped.has(scopeVariantId(variant))).length;
        const selectedVariants = variants.filter((variant) => selected.has(scopeVariantId(variant)));

        return `
            <div class="hew-card hew-family-edit-page">
                <p class="hew-eyebrow">Family bucket edit</p>
                <h2>Edit ${escapeHtml(editingFamily?.name || 'family')}</h2>
                <p class="hew-muted" style="margin:0 0 0.75rem">Adjust this family scope only. Other families stay locked so the same sellable cannot be added twice.</p>

                <div class="hew-scope-panel">
                    <div class="hew-scope-head">
                        <div>
                            <h3>Choose sellables for this family</h3>
                            <p>Use the filters, select or remove variants, then update this family.</p>
                        </div>
                        <div class="hew-scope-counts">
                            <span class="hew-badge manual">${selectedCount} selected</span>
                            <span class="hew-badge">${filtered.length} showing</span>
                            ${observedCount ? `<span class="hew-badge">${observedCount} observed</span>` : ''}
                        </div>
                    </div>

                    <div class="hew-scope-filters">
                        ${renderScopeFilter(match, 'main')}
                        ${renderScopeFilter(match, 'sub')}
                        ${renderScopeFilter(match, 'common')}
                    </div>

                    <div class="hew-scope-actions">
                        <button class="hew-btn primary" type="button" data-hew-action="select-scope-visible" ${availableFilteredCount ? '' : 'disabled'}>Select all matching (${availableFilteredCount})</button>
                        <button class="hew-btn" type="button" data-hew-action="deselect-scope-visible" ${selectedVisibleCount ? '' : 'disabled'}>Deselect matching</button>
                        <button class="hew-btn" type="button" data-hew-action="select-scope-observed" ${observedCount ? '' : 'disabled'}>Select observed</button>
                        <button class="hew-btn danger" type="button" data-hew-action="clear-scope-selection" ${selectedCount ? '' : 'disabled'}>Clear</button>
                    </div>

                    <p class="hew-muted" style="margin:0">${selectedVisibleCount} of the matching variants are selected. Variants already used by another family are locked.</p>

                    ${selectedVariants.length ? `
                        <div class="hew-selected-sellables">
                            <div class="hew-selected-sellables-head">
                                <div>
                                    <strong>Selected sellables</strong>
                                    <p>Remove individual variants here. Barcode, price, photos and location are edited later in Step 4.</p>
                                </div>
                                <span class="hew-badge manual">${selectedVariants.length}</span>
                            </div>
                            <div class="hew-selected-sellables-list">
                                ${selectedVariants.map((variant) => {
                                    const id = scopeVariantId(variant);
                                    return `
                                        <div class="hew-selected-sellable">
                                            <div>
                                                <strong>${escapeHtml(variant.display_name || `Variant ${id}`)}</strong>
                                                <span>
                                                    ${variant.main ? `<em>${escapeHtml(variant.main)}</em>` : ''}
                                                    ${variant.sub ? `<em>${escapeHtml(variant.sub)}</em>` : ''}
                                                    ${variant.common ? `<em>${escapeHtml(variant.common)}</em>` : ''}
                                                </span>
                                            </div>
                                            <button class="hew-btn danger" type="button" data-hew-action="remove-scope-selected-variant" data-variant-id="${id}">Remove</button>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    ` : ''}

                    <div class="hew-scope-list">
                        ${filtered.length ? filtered.map((variant) => {
                            const id = scopeVariantId(variant);
                            const checked = selected.has(id);
                            const alreadyGrouped = grouped.has(id);
                            return `
                                <label class="hew-scope-row ${checked ? 'is-selected' : ''} ${alreadyGrouped ? 'is-grouped' : ''}">
                                    <input type="checkbox" value="${id}" data-hew-scope-checkbox ${checked ? 'checked' : ''} ${alreadyGrouped ? 'disabled' : ''}>
                                    <span class="hew-scope-row-body">
                                        <strong>${escapeHtml(variant.display_name || `Variant ${id}`)}</strong>
                                        <span>
                                            ${variant.main ? `<em>${escapeHtml(variant.main)}</em>` : ''}
                                            ${variant.sub ? `<em>${escapeHtml(variant.sub)}</em>` : ''}
                                            ${variant.common ? `<em>${escapeHtml(variant.common)}</em>` : ''}
                                        </span>
                                    </span>
                                    ${alreadyGrouped ? '<span class="hew-badge">added</span>' : ''}
                                    ${variant.matches_observation ? '<span class="hew-badge manual">observed</span>' : ''}
                                </label>
                            `;
                        }).join('') : '<div class="hew-mini-card"><p class="hew-muted" style="margin:0">No variants match these filters.</p></div>'}
                    </div>
                </div>

                <div class="hew-bottom-bar">
                    <button class="hew-btn primary" type="button" data-hew-action="update-scope-family" ${selectedCount ? '' : 'disabled'}>Update family</button>
                    <button class="hew-btn danger" type="button" data-hew-action="cancel-scope-edit">Cancel family edit</button>
                </div>
            </div>
        `;
    };

    const renderStep2 = () => {
        const match = latestMatch();
        if (!match) {
            return `
                <div class="hew-card">
                    <h2>Waiting for Codex match</h2>
                    <p class="hew-muted">Your product has been saved. Come back to Codex and say: Check the new product.</p>
                    <div class="hew-bottom-bar">
                        <button class="hew-btn primary" type="button" data-hew-action="refresh-session">Refresh</button>
                        <button class="hew-btn" type="button" data-hew-action="go-step" data-step="1">Back</button>
                    </div>
                </div>
            `;
        }
        if (match.match_status === 'confirmed') {
            ensureVariantScope(match);
            const editingFamily = (state.variantScopeFamilies || [])
                .find((family) => String(family.uid) === String(state.editingScopeFamilyUid));
            if (editingFamily) {
                return renderScopeFamilyEditPage(match, editingFamily);
            }

            const style = match.matched_style || {};
            const variantCount = (match.variants || []).length;
            const observedCount = (match.variants || []).filter((v) => v.matches_observation).length;
            const familyCount = (state.variantScopeFamilies || []).length;
            return `
                <div class="hew-card">
                    <p class="hew-eyebrow">Match found</p>
                    <h2>${escapeHtml(style.style_name)}</h2>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem">
                        <span class="hew-badge">${(Number(match.confidence || 0) * 100).toFixed(0)}% confidence</span>
                        <span class="hew-badge">${variantCount} variants</span>
                        ${observedCount ? `<span class="hew-badge manual">${observedCount} observed</span>` : ''}
                    </div>
                    <p class="hew-muted" style="margin:0 0 0.75rem">${escapeHtml(match.reasoning)}</p>
                    ${renderVariantScope(match)}
                    <div class="hew-bottom-bar">
                        <button class="hew-btn primary" type="button" data-hew-action="accept-match" data-style-id="${style.style_id}" ${variantCount && !familyCount ? 'disabled' : ''}>Continue with ${familyCount} famil${familyCount === 1 ? 'y' : 'ies'}</button>
                        <button class="hew-btn danger" type="button" data-hew-action="go-step" data-step="1">Wrong</button>
                    </div>
                </div>
            `;
        }
        if (match.match_status === 'needs_user_choice') {
            return `
                <div class="hew-card">
                    <h2>Pick the right match</h2>
                    <p class="hew-muted" style="margin-bottom:0.75rem">${escapeHtml(match.reasoning)}</p>
                    <div class="hew-candidate-grid">
                        ${(match.candidates || []).map((candidate) => {
                            const style = candidate.matched_style || {};
                            return `
                                <button class="hew-candidate ${String(state.selectedCandidateId) === String(style.style_id) ? 'is-selected' : ''}" type="button" data-hew-action="select-candidate" data-style-id="${style.style_id}">
                                    <span class="hew-badge">${(Number(candidate.confidence || 0) * 100).toFixed(0)}%</span>
                                    <h3 style="margin:0.35rem 0 0.2rem">${escapeHtml(style.style_name)}</h3>
                                    <p class="hew-muted" style="margin:0;font-size:0.8rem">${escapeHtml(candidate.reasoning)}</p>
                                </button>
                            `;
                        }).join('')}
                    </div>
                    <div class="hew-bottom-bar">
                        <button class="hew-btn primary" type="button" data-hew-action="accept-selected" ${state.selectedCandidateId ? '' : 'disabled'}>Use selected</button>
                        <a class="hew-btn" href="${escapeHtml(routes.v2 || '#')}">Build from scratch</a>
                    </div>
                </div>
            `;
        }
        return `
            <div class="hew-card">
                <h2>Not found</h2>
                <p class="hew-muted">${escapeHtml(match.reasoning || 'No match found in the catalogue.')}</p>
                <div class="hew-bottom-bar">
                    <a class="hew-btn primary" href="${escapeHtml(routes.v2 || '#')}">Build from scratch</a>
                    <button class="hew-btn" type="button" data-hew-action="go-step" data-step="1">Try again</button>
                </div>
            </div>
        `;
    };

    const renderStep3 = () => {
        const variants = state.session?.variants || [];
        const familyGroups = state.session?.family_groups || [];
        const catalogueCount = variants.filter((v) => !v.manually_added).length;
        const manualCount = variants.filter((v) => v.manually_added).length;
        const groupSections = familyGroups.length
            ? familyGroups.map((group) => ({
                id: group.id,
                name: group.name,
                variants: variants.filter((variant) => String(variant.family_group_id || '') === String(group.id)),
            }))
            : [{ id: 'all', name: state.session?.matched_style?.name || 'Selected family', variants }];
        return `
            <div class="hew-card">
                <p class="hew-eyebrow">Verify match</p>
                <h2>${escapeHtml(state.session?.matched_style?.name || 'Matched style')}</h2>
                <div style="display:flex;gap:0.375rem;flex-wrap:wrap;margin-bottom:0.75rem">
                    ${familyGroups.length ? `<span class="hew-badge manual">${familyGroups.length} family buckets</span>` : ''}
                    <span class="hew-badge">${catalogueCount} from catalogue</span>
                    ${manualCount ? `<span class="hew-badge manual">${manualCount} manual</span>` : ''}
                    ${state.session?.matched_style?.line ? `<span class="hew-badge">${escapeHtml(state.session.matched_style.line)}</span>` : ''}
                    ${state.session?.matched_style?.type ? `<span class="hew-badge">${escapeHtml(state.session.matched_style.type)}</span>` : ''}
                </div>

                <div class="hew-family-review-list">
                    ${groupSections.map((group, index) => `
                        <section class="hew-family-review">
                            <div class="hew-family-review-head">
                                <div>
                                    <span class="hew-badge">Family ${index + 1}</span>
                                    <h3>${escapeHtml(group.name)}</h3>
                                </div>
                                <span class="hew-badge manual">${group.variants.length} variants</span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:0.375rem">
                                ${group.variants.map((variant) => `
                                    <div class="hew-mini-card" style="padding:0.625rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem">
                                        <div>
                                            <strong style="font-size:0.85rem">${escapeHtml(variant.display_name)}</strong>
                                            <div style="display:flex;gap:0.25rem;margin-top:0.15rem;flex-wrap:wrap">
                                                ${variant.main_value ? `<span class="hew-badge">${escapeHtml(variant.main_value)}</span>` : ''}
                                                ${variant.sub_value ? `<span class="hew-badge">${escapeHtml(variant.sub_value)}</span>` : ''}
                                                ${variant.common_value ? `<span class="hew-badge">${escapeHtml(variant.common_value)}</span>` : ''}
                                            </div>
                                        </div>
                                        ${variant.manually_added ? '<span class="hew-badge manual">manual</span>' : '<span class="hew-badge">catalogue</span>'}
                                    </div>
                                `).join('')}
                            </div>
                        </section>
                    `).join('')}
                </div>

                <details class="hew-card" style="box-shadow:none;border:1.5px dashed var(--hew-line)">
                    <summary style="cursor:pointer;font-weight:800;font-size:0.88rem;padding:0.25rem 0;list-style:none;display:flex;align-items:center;gap:0.375rem">
                        <span style="font-size:1.1rem">+</span> Add missing variant
                    </summary>
                    <form class="hew-form-grid" style="margin-top:0.75rem" data-hew-manual-variant-form>
                        <label class="hew-field">Main <input name="main_value" placeholder="Length / size"></label>
                        <label class="hew-field">Sub <input name="sub_value" placeholder="Colour"></label>
                        <label class="hew-field">Common <input name="common_value" placeholder="Pack count"></label>
                        <button class="hew-btn full" type="submit">Add variant</button>
                    </form>
                </details>

                <div class="hew-bottom-bar">
                    <button class="hew-btn primary" type="button" data-hew-action="start-filling">Start filling</button>
                    <button class="hew-btn danger" type="button" data-hew-action="wrong-match">Wrong match</button>
                </div>
            </div>
        `;
    };

    const filteredVariants = () => {
        const variants = state.session?.variants || [];
        const familyFiltered = state.selectedFamilyGroupId && state.selectedFamilyGroupId !== 'all'
            ? variants.filter((variant) => String(variant.family_group_id || '') === String(state.selectedFamilyGroupId))
            : variants;
        if (state.variantFilter === 'filled') return familyFiltered.filter((variant) => ['partial', 'complete'].includes(variant.status));
        if (state.variantFilter === 'manual') return familyFiltered.filter((variant) => variant.manually_added);
        return familyFiltered;
    };

    const selectedVariant = () => {
        const variants = filteredVariants();
        if (!state.selectedVariantId || !variants.some((variant) => String(variant.id) === String(state.selectedVariantId))) {
            state.selectedVariantId = (variants.find((variant) => !['complete', 'not_in_shop'].includes(variant.status)) || variants[0] || {}).id;
        }
        return variants.find((variant) => String(variant.id) === String(state.selectedVariantId)) || variants[0] || null;
    };

    const locationSelects = (variant) => {
        const stores = state.reference.stores || [];
        const store = stores.find((item) => String(item.id) === String(variant?.store_id)) || {};
        const sections = store.sections || [];
        const section = sections.find((item) => String(item.id) === String(variant?.section_id)) || {};
        const subsections = section.subsections || [];
        return `
            <div class="hew-three">
                <label class="hew-field">Store
                    <select name="store_id" data-hew-store-select>
                        <option value="">Choose store</option>
                        ${stores.map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.store_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                    </select>
                </label>
                <label class="hew-field">Section
                    <select name="section_id" data-hew-section-select>
                        <option value="">Choose section</option>
                        ${sections.map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.section_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                    </select>
                </label>
                <label class="hew-field">Subsection
                    <select name="subsection_id" data-hew-subsection-select>
                        <option value="">Optional</option>
                        ${subsections.map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.subsection_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                    </select>
                </label>
            </div>
        `;
    };

    const renderPhotos = (variant) => {
        const roles = ['family_main', 'variant_front', 'barcode', 'back', 'label_ingredients', 'shelf_context', 'gallery'];
        return `<div class="hew-photo-grid">${roles.map((role) => {
            const photos = (variant.photos || []).filter((photo) => photo.role === role);
            return `
                <div class="hew-photo-card">
                    <strong>${escapeHtml(role)}</strong>
                    ${photos.map((photo) => `
                        <div>
                            <img src="${escapeHtml(photo.url)}" alt="${escapeHtml(role)}">
                            <button class="hew-btn danger" type="button" data-hew-action="delete-photo" data-photo-id="${photo.id}" data-variant-id="${variant.id}">Delete</button>
                        </div>
                    `).join('')}
                    <label class="hew-btn">
                        Upload
                        <input type="file" accept="image/*" capture="environment" data-hew-photo-input data-role="${role}" data-variant-id="${variant.id}" hidden>
                    </label>
                </div>
            `;
        }).join('')}</div>`;
    };

    const renderStep4 = () => {
        const variants = filteredVariants();
        const allVariants = state.session?.variants || [];
        const familyGroups = state.session?.family_groups || [];
        const variant = selectedVariant();
        if (!variant) return `<div class="hew-card"><h2>No variants</h2></div>`;

        const allHandled = allVariants.length > 0 && allVariants.every((item) => ['complete', 'not_in_shop'].includes(item.status));
        const completeCount = allVariants.filter((v) => v.status === 'complete').length;
        const totalCount = allVariants.length;
        const photoCount = (variant.photos || []).length;
        const requiredPhotos = (variant.photos || []).filter((p) => ['variant_front'].includes(p.role)).length;

        return `
            <div class="hew-fill-layout">
                <div class="hew-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem">
                        <h2 style="margin:0">Variants</h2>
                        <span class="hew-badge">${completeCount}/${totalCount} done</span>
                    </div>
                    ${familyGroups.length ? `
                        <div class="hew-family-tabs">
                            <button class="hew-family-tab ${state.selectedFamilyGroupId === 'all' ? 'is-active' : ''}" type="button" data-hew-action="select-family-group" data-family-group-id="all">
                                <strong>All</strong>
                                <span>${allVariants.length}</span>
                            </button>
                            ${familyGroups.map((group, index) => `
                                <button class="hew-family-tab ${String(state.selectedFamilyGroupId) === String(group.id) ? 'is-active' : ''}" type="button" data-hew-action="select-family-group" data-family-group-id="${group.id}">
                                    <strong>${escapeHtml(group.name || `Family ${index + 1}`)}</strong>
                                    <span>${group.complete_count || 0}/${group.variant_count || 0}</span>
                                </button>
                            `).join('')}
                        </div>
                    ` : ''}
                    <div class="hew-inline-actions" style="margin-bottom:0.5rem">
                        ${['all', 'filled', 'manual'].map((filter) => `<button class="hew-btn ${state.variantFilter === filter ? 'primary' : ''}" type="button" data-hew-action="filter" data-filter="${filter}" style="padding:0.5rem 0.75rem;font-size:0.78rem;min-height:2.25rem">${filter}</button>`).join('')}
                    </div>
                    <div class="hew-variant-list">
                        ${variants.map((item) => {
                            const itemPhotos = (item.photos || []).length;
                            return `
                            <button class="hew-variant-row ${String(item.id) === String(variant.id) ? 'is-active' : ''}" type="button" data-hew-action="select-variant" data-variant-id="${item.id}">
                                <strong>${escapeHtml(item.display_name)}</strong>
                                <div style="display:flex;gap:0.25rem;align-items:center;flex-wrap:wrap;margin-top:0.2rem">
                                    <span class="hew-status ${escapeHtml(item.status)}">${escapeHtml(item.status)}</span>
                                    ${item.family_group_name ? `<span class="hew-badge">${escapeHtml(item.family_group_name)}</span>` : ''}
                                    ${item.manually_added ? '<span class="hew-badge manual">manual</span>' : ''}
                                    ${item.status !== 'empty' && item.status !== 'not_in_shop' ? `<span class="hew-badge">${itemPhotos} photo${itemPhotos !== 1 ? 's' : ''}</span>` : ''}
                                </div>
                            </button>
                        `;}).join('')}
                    </div>
                    <div class="hew-actions">
                        <button class="hew-btn warn full" type="button" data-hew-action="mark-unfilled" style="font-size:0.82rem">Mark all unfilled as not in shop</button>
                        <button class="hew-btn primary full" type="button" data-hew-action="done-review" ${allHandled ? '' : 'disabled'}>Done &mdash; review</button>
                    </div>
                </div>

                <form class="hew-card hew-form-grid" data-hew-variant-form data-variant-id="${variant.id}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem">
                        <h2 style="margin:0">${escapeHtml(variant.display_name)}</h2>
                        <span class="hew-status ${escapeHtml(variant.status)}">${escapeHtml(variant.status)}</span>
                    </div>

                    <div style="display:flex;gap:0.25rem;flex-wrap:wrap">
                        ${variant.family_group_name ? `<span class="hew-badge manual">${escapeHtml(variant.family_group_name)}</span>` : ''}
                        <span class="hew-badge">${escapeHtml(variant.main_value || 'Main ?')}</span>
                        <span class="hew-badge">${escapeHtml(variant.sub_value || 'Sub ?')}</span>
                        <span class="hew-badge">${escapeHtml(variant.common_value || 'Common ?')}</span>
                    </div>

                    <div style="display:flex;gap:0.5rem;margin-top:0.25rem">
                        <button class="hew-btn primary" type="button" data-hew-action="scan-barcode" style="flex:1;font-size:0.88rem">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="6" y1="8" x2="6" y2="16"/><line x1="10" y1="8" x2="10" y2="16"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="18" y1="8" x2="18" y2="16"/></svg>
                            Scan barcode
                        </button>
                        <button class="hew-btn" type="button" data-hew-action="generate-barcode" style="font-size:0.82rem">Generate</button>
                    </div>

                    <div class="hew-two">
                        <label class="hew-field">Barcode
                            <input name="barcode" value="${escapeHtml(variant.barcode || '')}" placeholder="Scan, generate, or type" inputmode="numeric">
                            <input name="barcode_source" type="hidden" value="${escapeHtml(variant.barcode_source || '')}">
                        </label>
                        <label class="hew-field">Price (${escapeHtml(variant.currency || 'GBP')})
                            <input name="price" type="number" step="0.01" min="0" value="${variant.price ?? ''}" placeholder="0.00" inputmode="decimal">
                            <input name="currency" type="hidden" value="${escapeHtml(variant.currency || 'GBP')}">
                        </label>
                    </div>

                    <div style="display:grid;gap:0.5rem">
                        <label class="hew-field">Store
                            <select name="store_id" data-hew-store-select>
                                <option value="">Choose store</option>
                                ${(state.reference.stores || []).map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.store_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                            </select>
                        </label>
                        <div class="hew-two">
                            <label class="hew-field">Section
                                <select name="section_id" data-hew-section-select>
                                    <option value="">Section</option>
                                    ${((state.reference.stores || []).find((s) => String(s.id) === String(variant?.store_id))?.sections || []).map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.section_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                                </select>
                            </label>
                            <label class="hew-field">Subsection
                                <select name="subsection_id" data-hew-subsection-select>
                                    <option value="">Optional</option>
                                    ${(((state.reference.stores || []).find((s) => String(s.id) === String(variant?.store_id))?.sections || []).find((s) => String(s.id) === String(variant?.section_id))?.subsections || []).map((item) => `<option value="${item.id}" ${String(item.id) === String(variant?.subsection_id) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                                </select>
                            </label>
                        </div>
                    </div>

                    <div>
                        <h3 style="margin-bottom:0.5rem">Photos <span class="hew-badge">${photoCount} uploaded${requiredPhotos < 1 ? ' · needs variant_front' : ''}</span></h3>
                        ${renderPhotos(variant)}
                    </div>

                    <div class="hew-bottom-bar">
                        <button class="hew-btn primary" type="submit" style="flex:2">Save & next</button>
                        <button class="hew-btn warn" type="button" data-hew-action="skip-variant" data-variant-id="${variant.id}">Skip</button>
                    </div>
                </form>
            </div>
        `;
    };

    const renderReview = (review) => {
        if (!review) return `<p class="hew-muted">Run the local pre-check first.</p>`;
        return `
            <div class="hew-summary-grid">
                <div class="hew-mini-card"><strong>${review.summary?.stocked_count || 0}</strong><p class="hew-muted">stocked</p></div>
                <div class="hew-mini-card"><strong>${review.summary?.inactive_count || 0}</strong><p class="hew-muted">not in shop</p></div>
                <div class="hew-mini-card"><strong>${review.summary?.total_in_session || 0}</strong><p class="hew-muted">total</p></div>
            </div>
            ${(review.issues || []).length ? `
                <div>${review.issues.map((issue) => `
                    <div class="hew-issue ${escapeHtml(issue.severity)}">
                        <span class="hew-badge">${escapeHtml(issue.severity)}</span>
                        <strong>${escapeHtml(issue.field)}</strong>
                        <p>${escapeHtml(issue.message)}</p>
                        ${issue.variant_id ? `<button class="hew-btn" type="button" data-hew-action="fix-variant" data-variant-id="${issue.variant_id}">Fix</button>` : ''}
                    </div>
                `).join('')}</div>
            ` : '<p class="hew-muted">No issues found.</p>'}
            ${(review.consistency_notes || []).length ? `<details><summary>Consistency notes</summary><ul>${review.consistency_notes.map((note) => `<li>${escapeHtml(note)}</li>`).join('')}</ul></details>` : ''}
        `;
    };

    const renderStep5 = () => `
        <div class="hew-card">
            <p class="hew-eyebrow">Pre-check</p>
            <h2>Local review</h2>
            ${renderReview(state.localReview)}
            <div class="hew-bottom-bar">
                <button class="hew-btn" type="button" data-hew-action="run-local-review">Run pre-check</button>
                <button class="hew-btn primary" type="button" data-hew-action="submit-review" ${state.localReview?.ready_to_publish ? '' : 'disabled'}>${state.busy ? 'Reviewing...' : 'Submit for review'}</button>
                <button class="hew-btn" type="button" data-hew-action="go-step" data-step="4">Back</button>
            </div>
        </div>
    `;

    const renderStep6 = () => {
        const review = latestReview();
        const blockers = (review?.issues || []).filter((issue) => issue.severity === 'blocker').length;
        const warnings = (review?.issues || []).filter((issue) => issue.severity === 'warning').length;
        const verdictClass = blockers ? 'blocker' : warnings ? 'warning' : '';
        const verdictText = blockers ? 'Fix required' : warnings ? 'Ready with warnings' : 'Ready to publish';
        const verdictColor = blockers ? 'var(--hew-danger)' : warnings ? 'var(--hew-warn)' : 'var(--hew-accent)';
        return `
            <div class="hew-card">
                <p class="hew-eyebrow">Final review</p>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem">
                    <div style="width:0.625rem;height:0.625rem;border-radius:50%;background:${verdictColor}"></div>
                    <h2 style="margin:0">${verdictText}</h2>
                </div>
                <div class="hew-summary-grid" style="margin-bottom:0.75rem">
                    <div class="hew-mini-card" style="text-align:center"><strong style="font-size:1.5rem">${review?.summary?.stocked_count || 0}</strong><p class="hew-muted" style="margin:0">stocked</p></div>
                    <div class="hew-mini-card" style="text-align:center"><strong style="font-size:1.5rem">${review?.summary?.inactive_count || 0}</strong><p class="hew-muted" style="margin:0">inactive</p></div>
                    <div class="hew-mini-card" style="text-align:center"><strong style="font-size:1.5rem">${review?.summary?.total_in_session || 0}</strong><p class="hew-muted" style="margin:0">total</p></div>
                </div>
                ${(review?.issues || []).length ? `
                    <div style="display:flex;flex-direction:column;gap:0.375rem">
                        ${review.issues.map((issue) => `
                            <div class="hew-issue ${escapeHtml(issue.severity)}" style="display:flex;align-items:flex-start;gap:0.5rem;justify-content:space-between">
                                <div>
                                    <span class="hew-badge" style="margin-bottom:0.25rem">${escapeHtml(issue.severity)}</span>
                                    <strong style="display:block;font-size:0.85rem">${escapeHtml(issue.field)}</strong>
                                    <p class="hew-muted" style="margin:0.15rem 0 0;font-size:0.82rem">${escapeHtml(issue.message)}</p>
                                </div>
                                ${issue.variant_id ? `<button class="hew-btn" type="button" data-hew-action="fix-variant" data-variant-id="${issue.variant_id}" style="flex-shrink:0;padding:0.5rem 0.75rem;font-size:0.78rem;min-height:2.25rem">Fix</button>` : ''}
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
                ${(review?.consistency_notes || []).length ? `
                    <details style="margin-top:0.75rem">
                        <summary style="cursor:pointer;font-weight:800;font-size:0.85rem">Consistency notes</summary>
                        <ul style="margin:0.5rem 0 0;padding-left:1.25rem">${review.consistency_notes.map((note) => `<li style="font-size:0.85rem;margin-bottom:0.25rem">${escapeHtml(note)}</li>`).join('')}</ul>
                    </details>
                ` : ''}
                <div class="hew-bottom-bar">
                    <button class="hew-btn" type="button" data-hew-action="go-step" data-step="4">Back to fix</button>
                    <button class="hew-btn" type="button" data-hew-action="submit-review">Re-run</button>
                    <button class="hew-btn primary" type="button" data-hew-action="approve" ${blockers === 0 && review ? '' : 'disabled'}>Approve</button>
                </div>
            </div>
        `;
    };

    const renderStep7 = () => {
        if (state.session?.status === 'published') {
            const completeCount = (state.session?.variants || []).filter((v) => v.status === 'complete').length;
            return `
                <div class="hew-card" style="text-align:center;padding:2rem 1rem">
                    <div style="width:3.5rem;height:3.5rem;border-radius:50%;background:var(--hew-accent);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <h2 style="margin-bottom:0.35rem">Published</h2>
                    <p class="hew-muted">${completeCount} variant${completeCount === 1 ? '' : 's'} are now live.</p>
                    <div class="hew-actions" style="justify-content:center">
                        <a class="hew-btn primary" href="${escapeHtml(state.session.published_family_url || '#')}">View product</a>
                        <a class="hew-btn" href="${escapeHtml(routes.index || '#')}">New intake</a>
                    </div>
                </div>
            `;
        }
        const completeCount = (state.session?.variants || []).filter((variant) => variant.status === 'complete').length;
        return `
            <div class="hew-card" style="text-align:center;padding:2rem 1rem">
                <h2>Publish ${completeCount} variant${completeCount === 1 ? '' : 's'}</h2>
                <p class="hew-muted">This will create or update the product family in your live catalogue.</p>
                <div class="hew-bottom-bar">
                    <button class="hew-btn primary full" type="button" data-hew-action="publish" ${state.busy ? 'disabled' : ''}>${state.busy ? 'Publishing...' : 'Publish now'}</button>
                </div>
            </div>
        `;
    };

    const renderBody = () => {
        if (!state.session) return renderEntry();
        switch (sessionStep()) {
            case 1: return renderEntry();
            case 2: return renderStep2();
            case 3: return renderStep3();
            case 4: return renderStep4();
            case 5: return renderStep5();
            case 6: return renderStep6();
            case 7: return renderStep7();
            default: return renderStep2();
        }
    };

    const render = () => {
        shell.innerHTML = `
            ${renderHeader()}
            <div class="hew-content">
                ${renderBody()}
            </div>
        `;
    };

    const tags = (value) => String(value || '').split(',').map((item) => item.trim()).filter(Boolean);

    const normalizeChipValue = (value) => String(value || '').replace(/\s+/g, ' ').trim();

    const chipValues = (field) => Array.from(field?.querySelectorAll('[data-hew-chip]') || [])
        .map((chip) => chip.dataset.hewChip || '')
        .filter(Boolean);

    const addChip = (input, rawValue) => {
        const value = normalizeChipValue(rawValue);
        if (!value) return;

        const field = input.closest('[data-hew-chip-field]');
        const box = input.closest('.hew-chip-box');
        if (!field || !box) return;

        const exists = chipValues(field).some((item) => item.toLowerCase() === value.toLowerCase());
        if (exists) return;

        const chip = document.createElement('span');
        chip.className = 'hew-chip';
        chip.dataset.hewChip = value;
        chip.innerHTML = `<span>${escapeHtml(value)}</span><button type="button" data-hew-remove-chip aria-label="Remove ${escapeHtml(value)}">x</button>`;
        box.insertBefore(chip, input);
    };

    const consumeChipInput = (input, includePending = false) => {
        const raw = input.value || '';
        if (!raw.trim()) return;

        if (includePending) {
            tags(raw).forEach((value) => addChip(input, value));
            input.value = '';
            return;
        }

        if (!raw.includes(',')) return;

        const endsWithComma = /,\s*$/.test(raw);
        const parts = raw.split(',');
        const pending = endsWithComma ? '' : parts.pop();
        parts.forEach((value) => addChip(input, value));
        input.value = pending || '';
    };

    const chipValuesForInput = (input) => {
        consumeChipInput(input, true);
        return chipValues(input.closest('[data-hew-chip-field]'));
    };

    const setCaptureStatus = (message, isError = false) => {
        const status = root.querySelector('[data-hew-capture-status]');
        if (!status) return;
        status.hidden = !message;
        status.textContent = message || '';
        status.classList.toggle('is-error', isError);
    };

    const updateCapturePreview = (file) => {
        if (!file) return;

        const area = root.querySelector('[data-hew-capture-area]');
        if (!area) return;

        state.capturePhotoFile = file;
        if (state.capturePhotoUrl) URL.revokeObjectURL(state.capturePhotoUrl);
        state.capturePhotoUrl = URL.createObjectURL(file);

        const existing = area.querySelector('.hew-capture-preview-wrap');
        if (existing) existing.remove();

        const wrap = document.createElement('div');
        wrap.className = 'hew-capture-preview-wrap';

        const img = document.createElement('img');
        img.className = 'hew-capture-preview';
        img.src = state.capturePhotoUrl;
        img.alt = 'Selected product photo preview';

        const actions = document.createElement('div');
        actions.className = 'hew-capture-preview-actions';
        actions.innerHTML = `
            <button class="hew-btn primary" type="button" data-hew-action="view-capture-photo">View photo</button>
            <button class="hew-btn danger" type="button" data-hew-action="remove-capture-photo">Remove</button>
        `;

        wrap.appendChild(img);
        wrap.appendChild(actions);
        area.appendChild(wrap);

        const icon = area.querySelector('.hew-capture-icon');
        const text = area.querySelector('.hew-capture-text');
        if (icon) icon.style.display = 'none';
        if (text) text.textContent = 'Photo selected. Choose another if needed.';
        setCaptureStatus('Photo ready. Use View photo to check it before saving.');
    };

    const clearCapturePreview = () => {
        const input = root.querySelector('input[data-hew-photo-preview]');
        const area = root.querySelector('[data-hew-capture-area]');
        if (input) input.value = '';
        state.capturePhotoFile = null;
        if (state.capturePhotoUrl) URL.revokeObjectURL(state.capturePhotoUrl);
        state.capturePhotoUrl = null;
        area?.querySelector('.hew-capture-preview-wrap')?.remove();
        const icon = area?.querySelector('.hew-capture-icon');
        const text = area?.querySelector('.hew-capture-text');
        if (icon) icon.style.display = '';
        if (text) text.textContent = 'Add product photo';
        setCaptureStatus('');
    };

    const openCapturePhotoViewer = () => {
        if (!state.capturePhotoUrl || !state.capturePhotoFile) {
            showToast('No product photo selected yet.', true);
            return;
        }

        const viewer = document.createElement('div');
        viewer.className = 'hew-photo-viewer';
        viewer.innerHTML = `
            <button class="hew-photo-viewer-backdrop" type="button" data-hew-close-photo-viewer></button>
            <div class="hew-photo-viewer-panel">
                <div class="hew-photo-viewer-head">
                    <strong>Product photo</strong>
                    <button class="hew-btn" type="button" data-hew-close-photo-viewer>Close</button>
                </div>
                <img src="${escapeHtml(state.capturePhotoUrl)}" alt="Selected product photo">
            </div>
        `;
        document.body.classList.add('is-modal-open');
        document.body.appendChild(viewer);

        viewer.addEventListener('click', (event) => {
            if (!event.target.closest('[data-hew-close-photo-viewer]')) return;
            viewer.remove();
            document.body.classList.remove('is-modal-open');
        });
    };

    const setCapturePhotoFile = (file) => {
        if (!file) return false;
        updateCapturePreview(file);
        return true;
    };

    const fileFromPasteEvent = (event) => {
        const items = Array.from(event.clipboardData?.items || []);
        const imageItem = items.find((item) => String(item.type || '').startsWith('image/'));
        const file = imageItem?.getAsFile();
        if (file) return file;

        return Array.from(event.clipboardData?.files || []).find((item) => String(item.type || '').startsWith('image/')) || null;
    };

    const pastePhotoFromClipboard = async () => {
        const pasteZone = root.querySelector('[data-hew-capture-paste]');
        pasteZone?.focus();
        pasteZone?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setCaptureStatus('Paste now. On phone, long-press inside the paste box and choose Paste. On desktop, press Ctrl+V.');
        showToast('Paste image inside the paste box.');
    };

    const createAndMatch = async (form) => {
        const payload = new FormData();
        payload.set('brand_catalogue_brand_id', form.brand_catalogue_brand_id.value);
        payload.set('style_name_hint', form.style_name_hint.value);
        payload.set('user_note', form.user_note.value);
        const photo = state.capturePhotoFile || form.querySelector('input[name="photo"]')?.files?.[0];
        if (!photo) {
            showToast('Take, upload, or paste a product photo first.', true);
            return;
        }
        payload.set('photo', photo);
        payload.set('observations[axes][main]', form.obs_main_axis.value);
        payload.set('observations[axes][sub]', form.obs_sub_axis.value);
        payload.set('observations[axes][common]', form.obs_common_axis.value);
        chipValuesForInput(form.obs_main).forEach((value, index) => payload.set(`observations[main][${index}]`, value));
        chipValuesForInput(form.obs_sub).forEach((value, index) => payload.set(`observations[sub][${index}]`, value));
        chipValuesForInput(form.obs_common).forEach((value, index) => payload.set(`observations[common][${index}]`, value));

        setBusy(true);
        try {
            const created = await api(routes.store, { method: 'POST', body: payload });
            setSession(created.session);
            showToast(created.message || 'Session saved for Codex check.');
        } catch (error) {
            showToast(error.message, true);
        } finally {
            setBusy(false);
        }
    };

    const patchSession = async (payload) => {
        const data = await api(sessionUrl(), {
            method: 'PATCH',
            body: JSON.stringify(payload),
            headers: { 'Content-Type': 'application/json' },
        });
        setSession(data.session);
        showToast(data.message || 'Saved.');
        render();
    };

    const refreshSession = async (showMessage = true) => {
        const data = await api(sessionUrl('/data'));
        setSession(data.session);
        state.reference = data.reference;
        if (showMessage) showToast('Session refreshed.');
        render();
    };

    const runLocalReview = async (goStep = false) => {
        const data = await api(sessionUrl('/local-review'));
        state.localReview = data.review;
        setSession(data.session);
        if (goStep) await patchSession({ current_step: 5 });
        render();
    };

    const optionHtml = (items, selected, placeholder) => `
        <option value="">${escapeHtml(placeholder)}</option>
        ${(items || []).map((item) => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
    `;

    const updateLocationChain = (target) => {
        const form = target.closest('[data-hew-variant-form]');
        if (!form) return false;

        const storeSelect = form.querySelector('[data-hew-store-select]');
        const sectionSelect = form.querySelector('[data-hew-section-select]');
        const subsectionSelect = form.querySelector('[data-hew-subsection-select]');
        if (!storeSelect || !sectionSelect || !subsectionSelect) return false;

        const store = (state.reference.stores || []).find((item) => String(item.id) === String(storeSelect.value));
        const sections = store?.sections || [];

        if (target === storeSelect) {
            sectionSelect.innerHTML = optionHtml(sections, '', 'Choose section');
            subsectionSelect.innerHTML = optionHtml([], '', 'Optional');
            return true;
        }

        if (target === sectionSelect) {
            const section = sections.find((item) => String(item.id) === String(sectionSelect.value));
            subsectionSelect.innerHTML = optionHtml(section?.subsections || [], '', 'Optional');
            return true;
        }

        return false;
    };

    root.addEventListener('submit', async (event) => {
        const step1 = event.target.closest('[data-hew-step1-form]');
        const manual = event.target.closest('[data-hew-manual-variant-form]');
        const variantForm = event.target.closest('[data-hew-variant-form]');
        if (!step1 && !manual && !variantForm) return;
        event.preventDefault();

        if (step1) {
            await createAndMatch(step1);
            return;
        }

        if (manual) {
            const payload = new FormData(manual);
            payload.set('manually_added', '1');
            const data = await api(sessionUrl('/variants'), { method: 'POST', body: payload });
            setSession(data.session);
            showToast(data.message || 'Manual variant added.');
            render();
            return;
        }

        if (variantForm) {
            const payload = new FormData(variantForm);
            payload.set('variant_id', variantForm.dataset.variantId);
            const data = await api(sessionUrl('/variants'), { method: 'POST', body: payload });
            setSession(data.session);
            const next = filteredVariants().find((variant) => !['complete', 'not_in_shop'].includes(variant.status))
                || (state.session.variants || []).find((variant) => !['complete', 'not_in_shop'].includes(variant.status));
            if (next) state.selectedVariantId = next.id;
            showToast(data.message || 'Variant saved.');
            render();
        }
    });

    root.addEventListener('change', async (event) => {
        if (updateLocationChain(event.target)) {
            return;
        }

        const scopeFilter = event.target.closest('[data-hew-scope-filter]');
        if (scopeFilter) {
            state.variantScopeFilters[scopeFilter.dataset.axis] = scopeFilter.value;
            render();
            return;
        }

        const scopeCheckbox = event.target.closest('[data-hew-scope-checkbox]');
        if (scopeCheckbox) {
            const ids = new Set((state.variantScopeSelectedIds || []).map(Number));
            const id = Number(scopeCheckbox.value || 0);
            if (id && scopeCheckbox.checked) ids.add(id);
            if (id && !scopeCheckbox.checked) ids.delete(id);
            state.variantScopeSelectedIds = [...ids];
            render();
            return;
        }

        const previewInput = event.target.closest('[data-hew-photo-preview]');
        if (previewInput && previewInput.files?.[0]) {
            updateCapturePreview(previewInput.files[0]);
            return;
        }

        const input = event.target.closest('[data-hew-photo-input]');
        if (!input || !input.files?.length) return;
        const payload = new FormData();
        payload.set('role', input.dataset.role);
        payload.set('photo', input.files[0]);
        try {
            const data = await api(sessionUrl(`/variants/${input.dataset.variantId}/photos`), { method: 'POST', body: payload });
            setSession(data.session);
            showToast(data.message || 'Photo uploaded.');
            render();
        } catch (error) {
            showToast(error.message, true);
        }
    });

    root.addEventListener('input', (event) => {
        const chipInput = event.target.closest('[data-hew-chip-input]');
        if (chipInput) {
            consumeChipInput(chipInput);
            return;
        }

        const input = event.target.closest('[data-hew-variant-form] input[name="barcode"]');
        if (!input) return;

        const source = input.closest('[data-hew-variant-form]')?.querySelector('input[name="barcode_source"]');
        if (source) source.value = input.value ? 'manual_typed' : '';
    });

    root.addEventListener('keydown', (event) => {
        const chipInput = event.target.closest('[data-hew-chip-input]');
        if (!chipInput || event.key !== 'Enter') return;

        event.preventDefault();
        consumeChipInput(chipInput, true);
    });

    root.addEventListener('paste', (event) => {
        const file = fileFromPasteEvent(event);
        if (!file) return;

        event.preventDefault();
        const pasteZone = root.querySelector('[data-hew-capture-paste]');
        if (pasteZone) pasteZone.textContent = '';
        if (setCapturePhotoFile(file)) showToast('Pasted photo added.');
    });

    root.addEventListener('click', async (event) => {
        const removeChip = event.target.closest('[data-hew-remove-chip]');
        if (removeChip) {
            event.preventDefault();
            removeChip.closest('[data-hew-chip]')?.remove();
            return;
        }

        const button = event.target.closest('[data-hew-action]');
        if (!button) return;
        event.preventDefault();
        const action = button.dataset.hewAction;

        try {
            if (action === 'refresh-session') {
                await refreshSession();
            } else if (action === 'choose-photo-camera' || action === 'choose-photo-library') {
                const input = root.querySelector('input[data-hew-photo-preview]');
                if (!input) return;

                if (action === 'choose-photo-camera') {
                    input.setAttribute('capture', 'environment');
                } else {
                    input.removeAttribute('capture');
                }

                input.click();
            } else if (action === 'paste-photo') {
                await pastePhotoFromClipboard();
            } else if (action === 'view-capture-photo') {
                openCapturePhotoViewer();
            } else if (action === 'remove-capture-photo') {
                clearCapturePreview();
            } else if (action === 'select-scope-visible') {
                const match = latestMatch();
                ensureVariantScope(match);
                const ids = new Set((state.variantScopeSelectedIds || []).map(Number));
                const grouped = groupedScopeSkuIds(state.editingScopeFamilyUid);
                filteredScopeVariants(match).forEach((variant) => {
                    const id = scopeVariantId(variant);
                    if (id && !grouped.has(id)) ids.add(id);
                });
                state.variantScopeSelectedIds = [...ids];
                render();
            } else if (action === 'deselect-scope-visible') {
                const match = latestMatch();
                ensureVariantScope(match);
                const filteredIds = new Set(filteredScopeVariants(match).map((variant) => scopeVariantId(variant)).filter(Boolean));
                state.variantScopeSelectedIds = (state.variantScopeSelectedIds || [])
                    .map(Number)
                    .filter((id) => !filteredIds.has(id));
                render();
            } else if (action === 'select-scope-observed') {
                const match = latestMatch();
                ensureVariantScope(match);
                state.variantScopeSelectedIds = matchVariants(match)
                    .filter((variant) => variant.matches_observation)
                    .map((variant) => scopeVariantId(variant))
                    .filter((id) => !groupedScopeSkuIds(state.editingScopeFamilyUid).has(id))
                    .filter(Boolean);
                render();
            } else if (action === 'add-scope-family') {
                const match = latestMatch();
                ensureVariantScope(match);
                const selectedSkuIds = (state.variantScopeSelectedIds || []).map(Number).filter(Boolean);
                if (!selectedSkuIds.length) {
                    showToast('Select variants for this family first.', true);
                    return;
                }

                const grouped = groupedScopeSkuIds();
                const duplicates = selectedSkuIds.filter((id) => grouped.has(id));
                if (duplicates.length) {
                    showToast('Some selected variants are already in another family. Remove that family first if needed.', true);
                    return;
                }

                state.variantScopeFamilies = [
                    ...(state.variantScopeFamilies || []),
                    {
                        uid: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
                        name: scopeFamilyName(match),
                        scope: {
                            filters: { ...(state.variantScopeFilters || {}) },
                            taxonomy: match?.variant_taxonomy || {},
                        },
                        sku_ids: selectedSkuIds,
                    },
                ];
                state.variantScopeSelectedIds = [];
                render();
            } else if (action === 'edit-scope-family') {
                const family = (state.variantScopeFamilies || [])
                    .find((item) => String(item.uid) === String(button.dataset.familyUid));
                if (!family) return;

                state.editingScopeFamilyUid = family.uid;
                state.variantScopeSelectedIds = (family.sku_ids || []).map(Number).filter(Boolean);
                state.variantScopeFilters = {
                    main: family.scope?.filters?.main || '',
                    sub: family.scope?.filters?.sub || '',
                    common: family.scope?.filters?.common || '',
                };
                render();
            } else if (action === 'update-scope-family') {
                const match = latestMatch();
                ensureVariantScope(match);
                const selectedSkuIds = (state.variantScopeSelectedIds || []).map(Number).filter(Boolean);
                if (!state.editingScopeFamilyUid || !selectedSkuIds.length) {
                    showToast('Select variants before updating the family.', true);
                    return;
                }

                const grouped = groupedScopeSkuIds(state.editingScopeFamilyUid);
                const duplicates = selectedSkuIds.filter((id) => grouped.has(id));
                if (duplicates.length) {
                    showToast('Some selected variants are already in another family.', true);
                    return;
                }

                state.variantScopeFamilies = (state.variantScopeFamilies || []).map((family) => {
                    if (String(family.uid) !== String(state.editingScopeFamilyUid)) return family;

                    const nextName = scopeFamilyName(match);

                    return {
                        ...family,
                        name: nextName || family.name,
                        scope: {
                            filters: { ...(state.variantScopeFilters || {}) },
                            taxonomy: match?.variant_taxonomy || family.scope?.taxonomy || {},
                        },
                        sku_ids: selectedSkuIds,
                    };
                });
                state.editingScopeFamilyUid = null;
                state.variantScopeSelectedIds = [];
                render();
            } else if (action === 'cancel-scope-edit') {
                state.editingScopeFamilyUid = null;
                state.variantScopeSelectedIds = [];
                render();
            } else if (action === 'remove-scope-family') {
                state.variantScopeFamilies = (state.variantScopeFamilies || [])
                    .filter((family) => String(family.uid) !== String(button.dataset.familyUid));
                if (String(state.editingScopeFamilyUid) === String(button.dataset.familyUid)) {
                    state.editingScopeFamilyUid = null;
                    state.variantScopeSelectedIds = [];
                }
                render();
            } else if (action === 'clear-scope-selection') {
                state.variantScopeSelectedIds = [];
                render();
            } else if (action === 'remove-scope-selected-variant') {
                const id = Number(button.dataset.variantId || 0);
                state.variantScopeSelectedIds = (state.variantScopeSelectedIds || [])
                    .map(Number)
                    .filter((selectedId) => selectedId !== id);
                render();
            } else if (action === 'accept-match') {
                const match = latestMatch();
                ensureVariantScope(match);
                const variants = matchVariants(match);
                const familyGroups = scopeFamilyPayload();
                if (variants.length && familyGroups.length === 0) {
                    showToast('Add at least one family bucket first.', true);
                    return;
                }
                await patchSession({
                    action: 'accept_match',
                    matched_style_id: button.dataset.styleId,
                    family_groups: familyGroups,
                });
            } else if (action === 'select-candidate') {
                state.selectedCandidateId = button.dataset.styleId;
                render();
            } else if (action === 'accept-selected') {
                await patchSession({ action: 'accept_match', matched_style_id: state.selectedCandidateId });
            } else if (action === 'wrong-match') {
                if (window.confirm('This will discard in-progress fill data for this session. Continue?')) {
                    await patchSession({ action: 'wrong_match' });
                }
            } else if (action === 'start-filling') {
                await patchSession({ current_step: 4, status: 'filling_variants' });
            } else if (action === 'go-step') {
                if (!state.session) {
                    showToast('Start or resume an intake first.', true);
                    return;
                }
                await patchSession({ current_step: button.dataset.step });
            } else if (action === 'filter') {
                state.variantFilter = button.dataset.filter;
                render();
            } else if (action === 'select-family-group') {
                state.selectedFamilyGroupId = button.dataset.familyGroupId || 'all';
                state.selectedVariantId = null;
                render();
            } else if (action === 'select-variant') {
                state.selectedVariantId = button.dataset.variantId;
                render();
            } else if (action === 'skip-variant') {
                const payload = new FormData();
                payload.set('variant_id', button.dataset.variantId);
                payload.set('status', 'not_in_shop');
                const data = await api(sessionUrl('/variants'), { method: 'POST', body: payload });
                setSession(data.session);
                const next = filteredVariants().find((variant) => !['complete', 'not_in_shop'].includes(variant.status))
                    || (state.session.variants || []).find((variant) => !['complete', 'not_in_shop'].includes(variant.status));
                if (next) state.selectedVariantId = next.id;
                showToast(data.message || 'Variant skipped.');
                render();
            } else if (action === 'generate-barcode') {
                const data = await api(sessionUrl('/barcode'), { method: 'POST' });
                const form = root.querySelector('[data-hew-variant-form]');
                const input = form?.querySelector('input[name="barcode"]');
                const source = form?.querySelector('input[name="barcode_source"]');
                if (input) input.value = data.barcode;
                if (source) source.value = data.barcode_source || 'generated_lhc';
                showToast('Barcode generated.');
            } else if (action === 'scan-barcode') {
                const form = root.querySelector('[data-hew-variant-form]');
                const input = form?.querySelector('input[name="barcode"]');
                const source = form?.querySelector('input[name="barcode_source"]');
                await openBarcodeScanner(input);
                if (input?.value && source) source.value = 'scanned';
            } else if (action === 'mark-unfilled') {
                if (window.confirm('Mark every unfilled variant as not in shop?')) {
                    const data = await api(sessionUrl('/variants/mark-unfilled'), { method: 'POST' });
                    setSession(data.session);
                    showToast(data.message || 'Updated.');
                    render();
                }
            } else if (action === 'done-review') {
                await runLocalReview(true);
            } else if (action === 'run-local-review') {
                await runLocalReview(false);
            } else if (action === 'submit-review') {
                setBusy(true);
                try {
                    const data = await api(sessionUrl('/review'), { method: 'POST' });
                    state.localReview = data.review;
                    setSession(data.session);
                    showToast(data.message || 'Review returned.');
                } catch (error) {
                    if (error.data?.review) {
                        state.localReview = error.data.review;
                        setSession(error.data.session);
                        await patchSession({ current_step: 5 });
                    }
                    throw error;
                }
            } else if (action === 'fix-variant') {
                state.selectedVariantId = button.dataset.variantId;
                await patchSession({ current_step: 4 });
            } else if (action === 'approve') {
                await patchSession({ current_step: 7, status: 'approved' });
            } else if (action === 'publish') {
                setBusy(true);
                const data = await api(sessionUrl('/publish'), { method: 'POST' });
                setSession(data.session);
                showToast(data.message || 'Published.');
            } else if (action === 'delete-photo') {
                const data = await api(sessionUrl(`/variants/${button.dataset.variantId}/photos/${button.dataset.photoId}`), { method: 'DELETE' });
                setSession(data.session);
                showToast(data.message || 'Photo removed.');
            } else if (action === 'save-exit') {
                window.location.href = routes.index || routes.sessions || window.location.href;
            }
        } catch (error) {
            showToast(error.message, true);
        } finally {
            if (state.busy) setBusy(false);
            else render();
        }
    });

    window.setInterval(() => {
        if (!state.session || state.busy || latestMatch()) return;
        if (state.session.status !== 'awaiting_match' || sessionStep() !== 2) return;
        refreshSession(false).catch(() => {});
    }, 5000);

    render();
};

initSidebar();
initBrandCarousels();
initPicturePreviewModal();
initPictureViewToggle();
initDeliverooBrandFilter();
initDeliverooProductFilter();
initDeliverooProductGallery();
initDeliverooManualProductForm();
initDeliverooImageUrlRows();
initDeliverooBrandCatalogue();
initDeliverooCatalogueLayout();
initDeliverooPriceModal();
initStyleWorkspaceAjaxV2();
initRetailMediaManagers();
initRetailFamilyManager();
initInventoryStructureAjax();
initHairIntakeWizard();
