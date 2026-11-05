(() => {
    document.querySelectorAll('[data-demo-login]').forEach(button => {
        button.addEventListener('click', () => {
            const emailInput = document.querySelector('#email');
            const passwordInput = document.querySelector('#password');
            const demoEmail = button.dataset.demoEmail || '';
            const demoPassword = button.dataset.demoPassword || '';
            if (!(emailInput instanceof HTMLInputElement) || !(passwordInput instanceof HTMLInputElement) || !demoEmail || !demoPassword) return;
            emailInput.value = demoEmail;
            passwordInput.value = demoPassword;
            emailInput.focus();
        });
    });

    const tableDropdownSelector = '.academic-row-actions [data-bs-toggle="dropdown"], .table-responsive [data-bs-toggle="dropdown"], .staff-table-wrap [data-bs-toggle="dropdown"]';
    const openTableDropdownSelector = '.academic-row-actions [data-bs-toggle="dropdown"].show, .table-responsive [data-bs-toggle="dropdown"].show, .staff-table-wrap [data-bs-toggle="dropdown"].show';
    const initializeTableDropdowns = root => {
        if (!window.bootstrap?.Dropdown) return;
        const toggles = [];
        if (root instanceof Element && root.matches(tableDropdownSelector)) toggles.push(root);
        if (root.querySelectorAll) toggles.push(...root.querySelectorAll(tableDropdownSelector));
        toggles.forEach(toggle => {
            window.bootstrap.Dropdown.getOrCreateInstance(toggle, {
                autoClose: true,
                popperConfig: defaults => ({
                    ...defaults,
                    strategy: 'fixed',
                    modifiers: (defaults.modifiers || []).map(modifier => modifier.name === 'flip'
                        ? { ...modifier, options: { ...modifier.options, fallbackPlacements: ['top-end', 'bottom-end', 'top-start', 'bottom-start'] } }
                        : modifier),
                }),
            });
        });
    };
    const closeTableDropdowns = except => {
        document.querySelectorAll(openTableDropdownSelector).forEach(toggle => {
            if (toggle === except) return;
            window.bootstrap?.Dropdown?.getInstance(toggle)?.hide();
        });
    };
    const initializeExistingTableDropdowns = () => initializeTableDropdowns(document);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeExistingTableDropdowns, { once: true });
    else initializeExistingTableDropdowns();
    const tableDropdownObserver = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node instanceof Element) initializeTableDropdowns(node);
        }));
    });
    tableDropdownObserver.observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('click', event => {
        const toggle = event.target instanceof Element ? event.target.closest(tableDropdownSelector) : null;
        if (toggle) {
            closeTableDropdowns(toggle);
            return;
        }
        if (!(event.target instanceof Element) || !event.target.closest('.dropdown-menu')) closeTableDropdowns();
        else if (event.target.closest('.dropdown-menu .dropdown-item')) closeTableDropdowns();
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openToggle = document.querySelector(openTableDropdownSelector);
        if (!openToggle) return;
        event.preventDefault();
        event.stopPropagation();
        window.bootstrap?.Dropdown?.getInstance(openToggle)?.hide();
        openToggle.focus({ preventScroll: true });
    }, true);
    document.addEventListener('scroll', () => closeTableDropdowns(), { capture: true, passive: true });

    document.querySelectorAll('[data-toast]').forEach(toastElement => {
        if (window.bootstrap?.Toast) {
            window.bootstrap.Toast.getOrCreateInstance(toastElement).show();
        }
    });

    const showToast = (type, message) => {
        const toastTypes = {
            success: ['Success', 'bi-check-circle-fill'],
            warning: ['Warning', 'bi-exclamation-triangle-fill'],
            danger: ['Error', 'bi-x-octagon-fill'],
            info: ['Information', 'bi-info-circle-fill'],
        };
        const [label, icon] = toastTypes[type] || toastTypes.info;
        let container = document.querySelector('[data-toast-container]');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3 admitflow-toast-container';
            container.dataset.toastContainer = '';
            container.setAttribute('aria-live', type === 'danger' ? 'assertive' : 'polite');
            container.setAttribute('aria-atomic', 'true');
            document.body.append(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast admitflow-toast toast-${type}`;
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        toast.dataset.toast = '';
        toast.dataset.bsAutohide = type === 'danger' ? 'false' : 'true';
        toast.dataset.bsDelay = type === 'danger' || type === 'warning' ? '12000' : '5500';
        const header = document.createElement('div');
        header.className = 'toast-header';
        const iconElement = document.createElement('i');
        iconElement.className = `bi ${icon} toast-type-icon`;
        iconElement.setAttribute('aria-hidden', 'true');
        const title = document.createElement('strong');
        title.className = 'me-auto';
        title.textContent = label;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.dataset.bsDismiss = 'toast';
        close.setAttribute('aria-label', 'Dismiss notification');
        header.append(iconElement, title, close);
        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        toast.append(header, body);
        container.append(toast);
        if (window.bootstrap?.Toast) window.bootstrap.Toast.getOrCreateInstance(toast).show();
    };

    document.querySelectorAll('form[data-auto-filter-form]').forEach(form => {
        let searchTimer = null;
        let isSubmitting = false;
        const panel = form.querySelector('[data-advanced-panel]');
        const advancedToggle = form.querySelector('[data-advanced-toggle]');
        const advancedClose = form.querySelector('[data-advanced-close]');
        const datePreset = form.querySelector('[data-date-preset]');
        const customRange = form.querySelector('[data-custom-range]');
        const rangeStart = form.querySelector('[data-range-start]');
        const rangeEnd = form.querySelector('[data-range-end]');
        const canonicalQuery = params => [...params.entries()].sort(([aKey, aValue], [bKey, bValue]) => aKey.localeCompare(bKey) || aValue.localeCompare(bValue)).map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
        const cleanParams = params => {
            [...params.entries()].forEach(([key, value]) => { if (value === '') params.delete(key); });
            return params;
        };
        const manilaToday = () => {
            const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
            const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
            return new Date(Date.UTC(Number(values.year), Number(values.month) - 1, Number(values.day)));
        };
        const dateValue = date => `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
        const updateDatePreset = clearDates => {
            if (!datePreset) return;
            const preset = datePreset.value;
            const custom = preset === 'custom';
            if (customRange) customRange.hidden = !custom;
            if (rangeStart) rangeStart.required = custom;
            if (rangeEnd) rangeEnd.required = custom;
            if (preset === 'last7' || preset === 'last30') {
                const end = manilaToday();
                const start = new Date(end);
                start.setUTCDate(start.getUTCDate() - (preset === 'last7' ? 6 : 29));
                if (rangeStart) rangeStart.value = dateValue(start);
                if (rangeEnd) rangeEnd.value = dateValue(end);
            } else if (clearDates && !custom) {
                if (rangeStart) rangeStart.value = '';
                if (rangeEnd) rangeEnd.value = '';
            }
        };
        const submitFilters = () => {
            if (isSubmitting) return;
            const formData = new FormData(form);
            const controlledKeys = new Set([...formData.keys()]);
            const params = cleanParams(new URLSearchParams(formData));
            const pageKeys = ['page', ...(form.dataset.pageParams || '').split(',').filter(Boolean)];
            pageKeys.forEach(key => params.delete(key));
            const current = cleanParams(new URLSearchParams(window.location.search));
            pageKeys.forEach(key => current.delete(key));
            [...current.entries()].forEach(([key, value]) => {
                if (!controlledKeys.has(key)) params.append(key, value);
            });
            if (canonicalQuery(params) === canonicalQuery(current)) return;
            const start = rangeStart;
            const end = rangeEnd;
            if (start && end && start.value && end.value) {
                end.setCustomValidity(start.value > end.value ? 'The end date must be on or after the start date.' : '');
            }
            if ((datePreset?.value === 'custom' || (start && end)) && !form.reportValidity()) return;
            isSubmitting = true;
            form.setAttribute('aria-busy', 'true');
            form.classList.add('is-loading');
            form.querySelector('[data-filter-status]')?.replaceChildren(document.createTextNode('Updating results…'));
            const fragment = form.dataset.filterTarget ? `#${encodeURIComponent(form.dataset.filterTarget)}` : '';
            const actionUrl = form.getAttribute('action') || window.location.pathname;
            window.location.assign(`${actionUrl}?${params.toString()}${fragment}`);
        };
        const setExpanded = expanded => {
            if (!panel || !advancedToggle) return;
            panel.hidden = !expanded;
            advancedToggle.setAttribute('aria-expanded', String(expanded));
            if (!expanded) advancedToggle.focus();
        };
        advancedToggle?.addEventListener('click', () => setExpanded(panel?.hidden ?? true));
        advancedClose?.addEventListener('click', () => setExpanded(false));
        panel?.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setExpanded(false);
            }
        });
        let refreshStaleDatePreset = false;
        if (datePreset) {
            if ((rangeStart?.value || rangeEnd?.value) && !datePreset.value) datePreset.value = 'custom';
            const previousStart = rangeStart?.value || '';
            const previousEnd = rangeEnd?.value || '';
            updateDatePreset(false);
            refreshStaleDatePreset = ['last7', 'last30'].includes(datePreset.value)
                && (previousStart !== rangeStart?.value || previousEnd !== rangeEnd?.value);
            datePreset.addEventListener('change', () => {
                updateDatePreset(true);
                submitFilters();
            });
        }
        form.querySelectorAll('[data-filter-change], [data-range-start], [data-range-end]').forEach(control => control.addEventListener('change', submitFilters));
        form.querySelectorAll('[data-filter-search]').forEach(input => {
            input.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(submitFilters, 350);
            });
            input.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    window.clearTimeout(searchTimer);
                    submitFilters();
                }
            });
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            window.clearTimeout(searchTimer);
            submitFilters();
        });
        form.querySelectorAll('[data-clear-filter]').forEach(button => button.addEventListener('click', () => {
            const names = (button.dataset.clearKeys || button.dataset.clearFilter).split(',');
            names.forEach(name => {
                const control = form.querySelector(`[name="${CSS.escape(name)}"]`);
                if (control) control.value = '';
            });
            if (datePreset && names.some(name => name === datePreset.name)) updateDatePreset(false);
            submitFilters();
        }));
        form.querySelectorAll('[data-reset-filter-form], a.btn-link, a.application-clear-all').forEach(button => button.addEventListener('click', event => {
            event.preventDefault();
            form.querySelectorAll('[name]').forEach(control => {
                if (control.name !== 'per_page') control.value = '';
            });
            if (datePreset) updateDatePreset(true);
            submitFilters();
        }));
        if (refreshStaleDatePreset) submitFilters();
    });

    const announcementForm = document.querySelector('[data-announcement-form]');
    if (announcementForm) {
        const announcementDrawer = announcementForm.closest('[data-form-drawer]');
        const scheduleFields = announcementForm.querySelector('[data-schedule-fields]');
        const scheduleDate = announcementForm.querySelector('[data-schedule-date]');
        const submitLabel = announcementForm.querySelector('[data-announcement-submit-label]');
        const submitButton = announcementForm.querySelector('[data-drawer-save]');
        const updateAnnouncementMode = () => {
            if (announcementForm.querySelector('[data-drawer-mode="add"]')?.hidden) return;
            const selectedMode = announcementForm.querySelector('[data-announcement-mode]:checked');
            if (!selectedMode) return;
            const scheduled = selectedMode.value === 'schedule';
            if (scheduleFields) scheduleFields.hidden = !scheduled;
            if (scheduleDate) scheduleDate.required = scheduled;
            if (submitLabel && submitButton) submitLabel.textContent = scheduled ? 'Schedule announcement' : 'Publish now';
        };
        announcementForm.querySelectorAll('[data-announcement-mode]').forEach(input => input.addEventListener('change', updateAnnouncementMode));
        updateAnnouncementMode();
        announcementDrawer?.addEventListener('shown.bs.offcanvas', updateAnnouncementMode);
    }

    if (window.bootstrap?.Modal) {
        const printPreviewModal = document.querySelector('[data-print-preview-modal]');
        if (printPreviewModal) {
            const studentDocumentPreview = printPreviewModal.dataset.previewKind === 'student';
            const previewFrame = printPreviewModal.querySelector('[data-preview-frame]');
            const previewLoading = printPreviewModal.querySelector('[data-preview-loading]');
            const previewError = printPreviewModal.querySelector('[data-preview-error]');
            const previewPrint = printPreviewModal.querySelector('[data-preview-print]');
            const signatureToggle = printPreviewModal.querySelector('[data-preview-signatures]');
            const pdfSignatureField = document.querySelector('[data-pdf-signatures]');
            const pdfOrientationField = document.querySelector('[data-pdf-orientation]');
            const orientationButtons = [...printPreviewModal.querySelectorAll('[data-preview-orientation]')];
            let previewTimer = 0;
            let previewUrl = '';
            let orientation = studentDocumentPreview ? 'portrait' : 'landscape';
            let previewReady = false;
            let modalOpen = false;
            let opener = null;

            const setPreviewError = () => {
                window.clearTimeout(previewTimer);
                previewReady = false;
                previewPrint.disabled = true;
                previewLoading.hidden = true;
                previewFrame.hidden = true;
                previewError.hidden = false;
            };

            const applyOrientation = () => {
                try {
                    const previewDocument = previewFrame.contentDocument;
                    if (!previewDocument || previewFrame.contentWindow.location.origin !== window.location.origin) return false;
                    const previewSheet = previewDocument.querySelector('[data-print-preview-sheet]');
                    if (!previewSheet) return false;
                    if (studentDocumentPreview) previewSheet.classList.remove('print-landscape');
                    else previewSheet.classList.toggle('print-landscape', orientation === 'landscape');
                    if (pdfOrientationField) pdfOrientationField.value = orientation;
                    return true;
                } catch {
                    return false;
                }
            };

            const applySignatures = () => {
                try {
                    const previewDocument = previewFrame.contentDocument;
                    if (!previewDocument || previewFrame.contentWindow.location.origin !== window.location.origin) return false;
                    const include = Boolean(signatureToggle?.checked);
                    previewDocument.body.classList.toggle('include-signature-lines', include);
                    const signatureBlock = previewDocument.querySelector('.report-preview-signatures');
                    if (signatureBlock) signatureBlock.hidden = !include;
                    return true;
                } catch {
                    return false;
                }
            };

            const setOrientation = (nextOrientation, persist = true) => {
                orientation = studentDocumentPreview || nextOrientation === 'portrait' ? 'portrait' : 'landscape';
                orientationButtons.forEach(button => {
                    const selected = button.dataset.previewOrientation === orientation;
                    button.classList.toggle('active', selected);
                    button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                if (persist && !studentDocumentPreview) {
                    try { window.sessionStorage.setItem('admitflow-report-orientation', orientation); } catch {}
                }
                if (pdfOrientationField) pdfOrientationField.value = orientation;
                if (previewReady) {
                    applyOrientation();
                    applySignatures();
                }
            };

            signatureToggle?.addEventListener('change', () => {
                if (pdfSignatureField) pdfSignatureField.value = signatureToggle.checked ? '1' : '0';
                if (previewReady) applySignatures();
            });

            if (!studentDocumentPreview) {
                try {
                    const savedOrientation = window.sessionStorage.getItem('admitflow-report-orientation');
                    if (savedOrientation === 'portrait' || savedOrientation === 'landscape') orientation = savedOrientation;
                } catch {}
            }
            setOrientation(orientation, false);

            const loadReportPreview = () => {
                window.clearTimeout(previewTimer);
                previewReady = false;
                previewPrint.disabled = true;
                previewLoading.hidden = false;
                previewError.hidden = true;
                previewFrame.hidden = true;
                if (!opener?.dataset.previewUrl) {
                    setPreviewError();
                    return;
                }
                previewUrl = new URL(opener.dataset.previewUrl, window.location.href).href;
                previewFrame.title = opener.dataset.previewTitle || 'Printable document preview';
                previewTimer = window.setTimeout(setPreviewError, 20000);
                previewFrame.src = previewUrl;
            };

            previewFrame.addEventListener('load', () => {
                if (!modalOpen) return;
                try {
                    const previewWindow = previewFrame.contentWindow;
                    const previewDocument = previewFrame.contentDocument;
                    if (!previewWindow || !previewDocument || previewWindow.location.origin !== window.location.origin || previewWindow.location.href !== previewUrl) return;
                    if (!previewDocument.querySelector('[data-print-preview-sheet]') || !applyOrientation() || !applySignatures()) {
                        setPreviewError();
                        return;
                    }
                    window.clearTimeout(previewTimer);
                    previewReady = true;
                    previewPrint.disabled = false;
                    previewLoading.hidden = true;
                    previewError.hidden = true;
                    previewFrame.hidden = false;
                } catch {
                    setPreviewError();
                }
            });

            orientationButtons.forEach((button, index) => {
                button.addEventListener('click', () => setOrientation(button.dataset.previewOrientation));
                button.addEventListener('keydown', event => {
                    let nextIndex = null;
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % orientationButtons.length;
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (index - 1 + orientationButtons.length) % orientationButtons.length;
                    if (event.key === 'Home') nextIndex = 0;
                    if (event.key === 'End') nextIndex = orientationButtons.length - 1;
                    if (nextIndex !== null) {
                        event.preventDefault();
                        orientationButtons[nextIndex].focus();
                        setOrientation(orientationButtons[nextIndex].dataset.previewOrientation);
                    }
                });
            });

            printPreviewModal.querySelector('[data-preview-retry]')?.addEventListener('click', loadReportPreview);
            previewPrint.addEventListener('click', () => {
                if (!previewReady || !applyOrientation()) return;
                previewFrame.contentWindow.focus();
                previewFrame.contentWindow.print();
            });
            printPreviewModal.addEventListener('show.bs.modal', event => {
                modalOpen = true;
                opener = event.relatedTarget || document.activeElement;
                if (studentDocumentPreview) {
                    setOrientation('portrait', false);
                } else {
                    try {
                        const savedOrientation = window.sessionStorage.getItem('admitflow-report-orientation');
                        setOrientation(savedOrientation === 'portrait' ? 'portrait' : 'landscape', false);
                    } catch {
                        setOrientation('landscape', false);
                    }
                }
            });
            printPreviewModal.addEventListener('shown.bs.modal', loadReportPreview);
            printPreviewModal.addEventListener('hidden.bs.modal', () => {
                modalOpen = false;
                window.clearTimeout(previewTimer);
                previewReady = false;
                previewPrint.disabled = true;
                previewFrame.hidden = true;
                if (opener?.isConnected) opener.focus({ preventScroll: true });
                previewFrame.src = 'about:blank';
            });
        }

        document.querySelectorAll('[data-create-form-modal]').forEach(modal => {
            modal.addEventListener('shown.bs.modal', () => modal.querySelector('.is-invalid, input:not([type="hidden"]), select, textarea')?.focus());
            if (modal.dataset.open === 'true') window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    }

    document.querySelectorAll('[data-form-drawer]').forEach(formDrawer => {
        if (!window.bootstrap?.Offcanvas) return;
        const drawerForm = formDrawer.querySelector('form[data-drawer-form]');
        const drawerTitle = formDrawer.querySelector('[data-drawer-title]');
        const drawerSubtitle = formDrawer.querySelector('[data-drawer-subtitle]');
        const drawerMetadata = formDrawer.querySelector('[data-drawer-metadata]');
        const saveButton = formDrawer.querySelector('[data-drawer-save]');
        let initialValues = '';
        let editMode = false;
        let drawerReturnFocus = null;
        const formValues = () => drawerForm ? JSON.stringify([...new FormData(drawerForm).entries()]) : '';
        const syncDrawerState = () => {
            const changed = formValues() !== initialValues;
            if (saveButton) saveButton.disabled = editMode && !changed;
            formDrawer.querySelectorAll('[data-character-count]').forEach(counter => {
                const field = drawerForm?.querySelector(`[name="${counter.dataset.characterCount}"]`);
                if (field) counter.textContent = `${field.value.length} / ${field.maxLength}`;
            });
        };
        const openFormDrawer = (record = null, forcedActive = null, confirmText = '', trigger = null) => {
            if (confirmText && !window.confirm(confirmText)) return;
            drawerReturnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
            drawerForm?.reset();
            drawerForm?.querySelectorAll('[data-drawer-id]').forEach(input => { input.value = record?.[input.name] ?? '0'; });
            editMode = record !== null;
            formDrawer.querySelectorAll('[data-drawer-mode]').forEach(modeElement => {
                const hidden = modeElement.dataset.drawerMode !== (editMode ? 'edit' : 'add');
                modeElement.hidden = hidden;
                modeElement.querySelectorAll('input, select, textarea, button').forEach(field => { field.disabled = hidden; });
            });
            if (!record) {
                drawerForm?.querySelectorAll('input:not([type="hidden"]), textarea').forEach(field => {
                    if (field.type === 'checkbox' || field.type === 'radio') field.checked = field.type === 'checkbox' && ['is_active', 'is_required'].includes(field.name) ? true : field.defaultChecked;
                    else field.value = '';
                });
                drawerForm?.querySelectorAll('select').forEach(field => { field.selectedIndex = 0; });
            }
            if (record) {
                const activeMode = drawerForm?.querySelector('[data-drawer-mode]:not([hidden])');
                Object.entries(record).forEach(([name, value]) => {
                    const field = (activeMode || drawerForm)?.querySelector(`[name="${CSS.escape(name)}"]`);
                    if (!field) return;
                    if (field.type === 'checkbox') field.checked = value === true || value === 1 || value === '1';
                    else field.value = value ?? '';
                });
            }
            drawerForm?.querySelectorAll('select[name="academic_program_id"] option[data-available], select[name="academic_year_id"] option[data-available]').forEach(option => {
                option.disabled = option.dataset.available !== 'true' && !(editMode && option.selected);
            });
            initialValues = formValues();
            if (forcedActive) {
                const field = drawerForm?.querySelector(`[name="${CSS.escape(forcedActive.name)}"]`);
                if (field) field.checked = forcedActive.value === '1';
            }
            if (drawerTitle) drawerTitle.textContent = `${editMode ? 'Edit' : 'Add'} ${formDrawer.dataset.recordLabel}`;
            if (drawerSubtitle) drawerSubtitle.textContent = editMode ? (record?.name || record?.label || record?.full_name || record?.title || 'Update record details') : 'Enter the record details below.';
            if (saveButton) {
                const label = saveButton.querySelector('[data-announcement-submit-label]');
                if (label && (editMode || !drawerForm?.querySelector('[data-announcement-mode]:checked'))) label.textContent = editMode ? saveButton.dataset.editLabel : saveButton.dataset.addLabel;
                else if (!label) saveButton.textContent = editMode ? saveButton.dataset.editLabel : saveButton.dataset.addLabel;
            }
            if (drawerMetadata) {
                drawerMetadata.hidden = !editMode;
                drawerMetadata.querySelector('[data-created-metadata]').textContent = record?.created_at ? `Created ${record.created_at} UTC` : '';
                drawerMetadata.querySelector('[data-updated-metadata]').textContent = record?.updated_at ? `Updated ${record.updated_at} UTC` : '';
            }
            formDrawer.querySelectorAll('[data-server-error]').forEach(error => { error.hidden = true; });
            if (!editMode) drawerForm?.querySelector('[data-announcement-mode]:checked')?.dispatchEvent(new Event('change', { bubbles: true }));
            syncDrawerState();
            window.bootstrap.Offcanvas.getOrCreateInstance(formDrawer).show();
        };
        const drawerSelector = `#${CSS.escape(formDrawer.id)}`;
        document.querySelectorAll(`[data-drawer-add="${drawerSelector}"], [data-academic-add]`).forEach(button => button.addEventListener('click', event => openFormDrawer(null, null, '', event.currentTarget)));
        document.querySelectorAll(`[data-drawer-edit="${drawerSelector}"], [data-academic-edit]`).forEach(button => button.addEventListener('click', event => {
            let record = {};
            try { record = JSON.parse(event.currentTarget.dataset.record || '{}'); } catch {}
            openFormDrawer(record, null, '', event.currentTarget.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]') || event.currentTarget);
        }));
        document.querySelectorAll(`[data-drawer-open="${drawerSelector}"]`).forEach(button => button.addEventListener('click', event => {
            let record = {};
            try { record = JSON.parse(event.currentTarget.dataset.drawerContent || '{}'); } catch {}
            drawerReturnFocus = event.currentTarget;
            if (drawerTitle) drawerTitle.textContent = record.action ? String(record.action).replace(/[._]/g, ' ') : 'Audit event';
            if (drawerSubtitle) drawerSubtitle.textContent = record.created_at ? `${record.created_at} UTC` : 'Recorded event information';
            const body = formDrawer.querySelector('[data-drawer-body]');
            if (body) {
                body.replaceChildren();
                const list = document.createElement('dl');
                list.className = 'audit-detail-list';
                const fields = [['Acting user', record.actor], ['User ID', record.user_id], ['Current role', record.role], ['Action', record.action], ['Module', record.module], ['Affected record ID', record.entity_id], ['Result', record.outcome], ['Severity', record.severity], ['IP address', record.ip_address], ['Date and time (UTC)', record.created_at]];
                fields.forEach(([label, value]) => {
                    const row = document.createElement('div');
                    const term = document.createElement('dt');
                    const definition = document.createElement('dd');
                    term.textContent = label;
                    definition.textContent = value === null || value === undefined || value === '' ? 'Not recorded' : String(value);
                    row.append(term, definition);
                    list.append(row);
                });
                body.append(list);
                const details = record.details;
                const detailSection = document.createElement('section');
                detailSection.className = 'audit-json-details';
                const heading = document.createElement('h3');
                heading.textContent = 'Event details';
                detailSection.append(heading);
                if (!details || typeof details !== 'object' || Object.keys(details).length === 0) {
                    const empty = document.createElement('p');
                    empty.textContent = 'No additional event details were recorded.';
                    detailSection.append(empty);
                } else {
                    ['before', 'after'].forEach(key => {
                        if (!(key in details)) return;
                        const section = document.createElement('section');
                        const subheading = document.createElement('h4');
                        const pre = document.createElement('pre');
                        subheading.textContent = key === 'before' ? 'Previous values' : 'New values';
                        pre.textContent = JSON.stringify(details[key], null, 2);
                        section.append(subheading, pre);
                        detailSection.append(section);
                    });
                    const remaining = Object.fromEntries(Object.entries(details).filter(([key]) => !['before', 'after'].includes(key)));
                    if (Object.keys(remaining).length > 0) {
                        const pre = document.createElement('pre');
                        pre.textContent = JSON.stringify(remaining, null, 2);
                        detailSection.append(pre);
                    }
                }
                body.append(detailSection);
            }
            window.bootstrap.Offcanvas.getOrCreateInstance(formDrawer).show();
        }))
        drawerForm?.addEventListener('input', syncDrawerState);
        drawerForm?.addEventListener('change', syncDrawerState);
        drawerForm?.querySelectorAll('[data-uppercase]').forEach(field => field.addEventListener('input', () => {
            const start = field.selectionStart;
            const end = field.selectionEnd;
            field.value = field.value.toUpperCase();
            field.setSelectionRange(start, end);
        }));
        formDrawer.addEventListener('hide.bs.offcanvas', event => {
            if (formValues() !== initialValues && !window.confirm('Discard unsaved changes?')) event.preventDefault();
        });
        formDrawer.addEventListener('shown.bs.offcanvas', () => {
            (formDrawer.querySelector('.is-invalid') || formDrawer.querySelector('input:not([type="hidden"]), select, textarea') || formDrawer.querySelector('[data-bs-dismiss="offcanvas"]') || formDrawer)?.focus();
        });
        formDrawer.addEventListener('hidden.bs.offcanvas', () => {
            if (drawerReturnFocus instanceof HTMLElement && drawerReturnFocus.isConnected) drawerReturnFocus.focus();
        });
        drawerForm?.addEventListener('submit', event => {
            if (drawerForm && !drawerForm.checkValidity()) {
                event.preventDefault();
                drawerForm.reportValidity();
                return;
            }
            if (drawerForm?.dataset.confirmEdit && editMode && !window.confirm(drawerForm.dataset.confirmEdit)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
        if (formDrawer.dataset.open === 'true') {
            editMode = formDrawer.dataset.editing === 'true';
            formDrawer.querySelectorAll('[data-drawer-mode]').forEach(modeElement => {
                const hidden = modeElement.dataset.drawerMode !== (editMode ? 'edit' : 'add');
                modeElement.hidden = hidden;
                modeElement.querySelectorAll('input, select, textarea, button').forEach(field => { field.disabled = hidden; });
            });
            if (drawerTitle) drawerTitle.textContent = `${editMode ? 'Edit' : 'Add'} ${formDrawer.dataset.recordLabel}`;
            if (saveButton) {
                const label = saveButton.querySelector('[data-announcement-submit-label]');
                if (label && (editMode || !drawerForm?.querySelector('[data-announcement-mode]:checked'))) label.textContent = editMode ? saveButton.dataset.editLabel : saveButton.dataset.addLabel;
                else if (!label) saveButton.textContent = editMode ? saveButton.dataset.editLabel : saveButton.dataset.addLabel;
            }
            initialValues = '';
            syncDrawerState();
            window.bootstrap.Offcanvas.getOrCreateInstance(formDrawer).show();
        }
    });

    document.querySelectorAll('[data-page-size-select]').forEach(select => select.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set(select.dataset.pageSizeSelect, select.value);
        url.searchParams.delete(select.dataset.pageParam || 'page');
        window.location.assign(url.toString());
    }));

    document.querySelectorAll('form[data-submit-once]').forEach(form => form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        const submitButton = event.submitter instanceof HTMLElement && event.submitter.matches('button[type="submit"], input[type="submit"]')
            ? event.submitter
            : form.querySelector('button[type="submit"]:not(:disabled), input[type="submit"]:not(:disabled)');
        if (!submitButton) return;
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        const loadingLabel = submitButton.dataset.loadingLabel;
        if (loadingLabel) submitButton.textContent = loadingLabel;
    }));

    const avatarMenu = document.querySelector('[data-avatar-menu]');
    const avatarToggle = avatarMenu?.querySelector('[data-avatar-menu-toggle]');
    const avatarPopover = avatarMenu?.querySelector('[role="menu"]');
    const avatarInput = document.querySelector('[data-avatar-input]');
    const avatarPreview = document.querySelector('[data-avatar-preview]');
    const avatarFallback = document.querySelector('[data-avatar-fallback]');
    const avatarFeedback = document.querySelector('[data-avatar-feedback]');
    const previewOption = avatarMenu?.querySelector('[data-avatar-preview-open]');
    const avatarSaveButton = document.querySelector('[data-avatar-save]');
    const avatarPreviewDialog = document.querySelector('[data-avatar-preview-dialog]');
    const dialogImage = avatarPreviewDialog?.querySelector('[data-avatar-dialog-image]');
    let previewUrl = null;
    if (avatarMenu && avatarToggle && avatarPopover) {
        const menuItems = () => [...avatarPopover.querySelectorAll('[role="menuitem"]')].filter(item => !item.hidden && !item.disabled);
        const closeAvatarMenu = (restoreFocus = false) => {
            avatarPopover.hidden = true;
            avatarToggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus) avatarToggle.focus();
        };
        const openAvatarMenu = () => {
            avatarPopover.hidden = false;
            avatarToggle.setAttribute('aria-expanded', 'true');
            menuItems()[0]?.focus();
        };
        avatarToggle.addEventListener('click', () => {
            if (avatarPopover.hidden) openAvatarMenu();
            else closeAvatarMenu();
        });
        avatarPopover.addEventListener('click', event => {
            const confirmButton = event.target.closest('[data-confirm]');
            if (confirmButton) {
                event.preventDefault();
                closeAvatarMenu();
                if (!confirm(confirmButton.dataset.confirm || 'Remove this profile picture?')) return;
                const form = confirmButton.getAttribute('form') ? document.getElementById(confirmButton.getAttribute('form')) : null;
                if (form) form.requestSubmit();
                return;
            }
            if (event.target.closest('[data-avatar-upload]')) {
                closeAvatarMenu();
                avatarInput?.click();
            } else if (event.target.closest('[data-avatar-preview-open]')) {
                closeAvatarMenu();
                if (previewUrl && avatarPreviewDialog?.showModal) avatarPreviewDialog.showModal();
            }
        });
        avatarPopover.addEventListener('keydown', event => {
            const items = menuItems();
            const currentIndex = items.indexOf(document.activeElement);
            let nextIndex = null;
            if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
            if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = items.length - 1;
            if (nextIndex !== null && items.length > 0) {
                event.preventDefault();
                items[nextIndex].focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeAvatarMenu(true);
            } else if (event.key === 'Tab') {
                closeAvatarMenu();
            }
        });
        document.addEventListener('click', event => {
            if (!avatarMenu.contains(event.target) && !avatarPopover.hidden) closeAvatarMenu();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !avatarPopover.hidden) closeAvatarMenu(true);
        });
    }

    let savedAvatarSource = '';
    if (avatarInput && avatarPreview && avatarFallback) {
        savedAvatarSource = avatarPreview.getAttribute('src') || '';
        const restoreSavedAvatar = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            if (savedAvatarSource) avatarPreview.src = savedAvatarSource;
            else avatarPreview.removeAttribute('src');
            avatarPreview.hidden = savedAvatarSource === '';
            avatarFallback.hidden = savedAvatarSource !== '';
            if (dialogImage) dialogImage.removeAttribute('src');
            if (previewOption) previewOption.hidden = true;
            if (avatarSaveButton) avatarSaveButton.hidden = true;
            avatarToggle?.setAttribute('aria-label', 'Edit profile picture');
        };
        const discardAvatarPreview = () => {
            avatarInput.value = '';
            restoreSavedAvatar();
            avatarFeedback.textContent = 'Photo selection cancelled. Your saved profile picture is unchanged.';
            if (avatarPreviewDialog?.open) avatarPreviewDialog.close();
        };
        document.addEventListener('profile:cancel-edit', () => {
            if (previewUrl) discardAvatarPreview();
        });
        avatarInput.addEventListener('change', () => {
            const file = avatarInput.files?.[0];
            avatarInput.setCustomValidity('');
            if (!file) {
                restoreSavedAvatar();
                avatarFeedback.textContent = 'JPEG or PNG, maximum 2 MB.';
                return;
            }
            if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 2 * 1024 * 1024) {
                avatarInput.setCustomValidity('Choose a JPEG or PNG image no larger than 2 MB.');
                avatarFeedback.textContent = 'Choose a JPEG or PNG image no larger than 2 MB.';
                avatarInput.reportValidity();
                avatarInput.value = '';
                restoreSavedAvatar();
                return;
            }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(file);
            avatarPreview.src = previewUrl;
            if (dialogImage) dialogImage.src = previewUrl;
            avatarPreview.hidden = false;
            avatarFallback.hidden = true;
            if (previewOption) previewOption.hidden = false;
            if (avatarSaveButton) avatarSaveButton.hidden = false;
            avatarFeedback.textContent = `${file.name} selected. Preview only; upload to save.`;
            avatarMenu?.querySelector('[data-avatar-menu-toggle]')?.setAttribute('aria-label', 'Preview selected profile photo and edit profile picture');
            if (avatarPopover && !avatarPopover.hidden) {
                avatarPopover.hidden = true;
                avatarToggle?.setAttribute('aria-expanded', 'false');
            }
            avatarPreview.addEventListener('error', () => {
                restoreSavedAvatar();
                avatarFeedback.textContent = 'This image could not be previewed. Choose another image.';
                avatarInput.value = '';
                if (avatarPreviewDialog?.open) avatarPreviewDialog.close();
            }, { once: true });
        });
        avatarInput.closest('form')?.addEventListener('submit', event => {
            if (!avatarInput.files?.length || !avatarInput.checkValidity()) {
                event.preventDefault();
                avatarInput.reportValidity();
                return;
            }
            avatarFeedback.textContent = 'Uploading profile photo...';
        });
        avatarPreviewDialog?.querySelectorAll('[data-avatar-preview-cancel]').forEach(button => button.addEventListener('click', discardAvatarPreview));
        avatarPreviewDialog?.addEventListener('cancel', event => {
            event.preventDefault();
            discardAvatarPreview();
        });
        avatarPreviewDialog?.addEventListener('click', event => {
            if (event.target === avatarPreviewDialog) avatarPreviewDialog.close();
        });
        window.addEventListener('pagehide', () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
        }, { once: true });
    }

    const profileCard = document.querySelector('[data-profile-card]');
    if (profileCard) {
        const profileEditToggle = profileCard.querySelector('[data-profile-edit-toggle]');
        const profileView = profileCard.querySelector('[data-profile-view]');
        const profileEdit = profileCard.querySelector('[data-profile-edit]');
        const profileCancel = profileCard.querySelector('[data-profile-cancel]');
        const profileInputs = profileEdit ? [...profileEdit.querySelectorAll('input, select')] : [];
        let profileInitialValues = {};

        const captureProfileValues = () => {
            profileInitialValues = {};
            profileInputs.forEach(input => {
                if (input.name) profileInitialValues[input.name] = input.dataset.profileSavedValue ?? input.value;
            });
        };
        const clearProfileFieldErrors = () => {
            profileEdit.querySelectorAll('.settings-field-error').forEach(error => error.remove());
            profileEdit.querySelectorAll('[aria-invalid="true"]').forEach(input => {
                input.removeAttribute('aria-invalid');
                input.classList.remove('is-invalid');
            });
        };
        const restoreProfileValues = () => {
            profileInputs.forEach(input => {
                if (input.name in profileInitialValues) input.value = profileInitialValues[input.name];
            });
        };
        const clearLocalAvatarSelection = () => {
            if (!avatarInput) return;
            avatarInput.value = '';
            if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
            if (avatarPreview) {
                if (savedAvatarSource) { avatarPreview.src = savedAvatarSource; avatarPreview.hidden = false; }
                else { avatarPreview.removeAttribute('src'); avatarPreview.hidden = true; }
            }
            if (avatarFallback) avatarFallback.hidden = savedAvatarSource !== '';
            if (dialogImage) dialogImage.removeAttribute('src');
            if (previewOption) previewOption.hidden = true;
            if (avatarSaveButton) avatarSaveButton.hidden = true;
            if (avatarFeedback) avatarFeedback.textContent = '';
        };
        const openProfileEdit = () => {
            if (profileView) profileView.hidden = true;
            if (profileEdit) profileEdit.hidden = false;
            if (profileEditToggle) {
                profileEditToggle.hidden = true;
                profileEditToggle.setAttribute('aria-expanded', 'true');
            }
            profileEdit?.querySelector('input:not([readonly]), select, textarea')?.focus();
        };
        const closeProfileEdit = () => {
            restoreProfileValues();
            clearProfileFieldErrors();
            clearLocalAvatarSelection();
            if (profileView) profileView.hidden = false;
            if (profileEdit) profileEdit.hidden = true;
            if (profileEditToggle) {
                profileEditToggle.hidden = false;
                profileEditToggle.setAttribute('aria-expanded', 'false');
                profileEditToggle.focus();
            }
        };
        captureProfileValues();
        profileEditToggle.addEventListener('click', openProfileEdit);
        profileCancel.addEventListener('click', closeProfileEdit);
    }

    const notificationMenu = document.querySelector('[data-notification-menu]');
    if (notificationMenu) {
        const notificationToggle = notificationMenu.querySelector('[data-notification-toggle]');
        const notificationPanel = notificationMenu.querySelector('[data-notification-panel]');
        const closeNotifications = () => {
            notificationPanel.hidden = true;
            notificationToggle.setAttribute('aria-expanded', 'false');
        };
        const postNotificationAction = async (path, values = {}) => {
            const body = new URLSearchParams({ _csrf: notificationMenu.dataset.csrf, ...values });
            try {
                const response = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
                const result = await response.json().catch(() => ({ ok: false }));
                return { ok: response.ok && result.ok === true, message: result.message || 'The notification could not be updated.', unreadCount: Number.isInteger(result.unread_count) ? result.unread_count : null };
            } catch {
                return { ok: false, message: 'Could not update the notification. Please try again.' };
            }
        };
        const updateUnreadCount = count => {
            if (!Number.isInteger(count)) return;
            const countLabel = notificationPanel.querySelector('[data-notification-unread-count]');
            const badge = notificationToggle.querySelector('.notification-badge');
            if (countLabel) countLabel.textContent = `${count} unread`;
            notificationToggle.setAttribute('aria-label', `Notifications${count > 0 ? `, ${count} unread` : ''}`);
            if (count > 0) {
                const nextBadge = badge || document.createElement('span');
                nextBadge.className = 'notification-badge';
                nextBadge.textContent = count > 99 ? '99+' : String(count);
                if (!badge) notificationToggle.append(nextBadge);
            } else badge?.remove();
        };
        const updateNotificationItem = (button, count) => {
            const item = button.closest('.notification-dropdown-item');
            if (button.dataset.derivedKey) item?.remove();
            else {
                item?.classList.remove('is-unread');
                button.remove();
            }
            updateUnreadCount(count);
        };
        notificationToggle.addEventListener('click', () => {
            const opening = notificationPanel.hidden;
            notificationPanel.hidden = !opening;
            notificationToggle.setAttribute('aria-expanded', String(opening));
            if (opening) notificationPanel.querySelector('a, button')?.focus();
        });
        notificationMenu.addEventListener('click', async event => {
            const notificationLink = event.target.closest('[data-notification-link]');
            if (notificationLink) {
                const notificationId = notificationLink.dataset.notificationId;
                const derivedKey = notificationLink.dataset.derivedKey;
                if (notificationId || derivedKey) {
                    event.preventDefault();
                    if (notificationLink.dataset.pending === 'true') return;
                    notificationLink.dataset.pending = 'true';
                    notificationLink.setAttribute('aria-busy', 'true');
                    const result = await postNotificationAction(notificationId ? '/notifications/read' : '/notifications/dismiss-derived', notificationId ? { notification_id: notificationId } : { key: derivedKey });
                    if (!result.ok) {
                        delete notificationLink.dataset.pending;
                        notificationLink.removeAttribute('aria-busy');
                        showToast('danger', result.message);
                        return;
                    }
                    updateUnreadCount(result.unreadCount);
                    window.location.assign(notificationLink.href);
                }
                return;
            }
            const markButton = event.target.closest('[data-notification-mark]');
            if (markButton) {
                if (markButton.disabled) return;
                markButton.disabled = true;
                const notificationId = markButton.dataset.notificationId;
                const derivedKey = markButton.dataset.derivedKey;
                const result = await postNotificationAction(notificationId ? '/notifications/read' : '/notifications/dismiss-derived', notificationId ? { notification_id: notificationId } : { key: derivedKey });
                showToast(result.ok ? 'success' : 'danger', result.message);
                if (result.ok) updateNotificationItem(markButton, result.unreadCount);
                else markButton.disabled = false;
                return;
            }
            const markAllButton = event.target.closest('[data-notification-mark-all]');
            if (markAllButton) {
                if (markAllButton.disabled) return;
                markAllButton.disabled = true;
                const result = await postNotificationAction('/notifications/read-all');
                showToast(result.ok ? 'success' : 'danger', result.message);
                if (result.ok) {
                    notificationPanel.querySelectorAll('.notification-dropdown-item').forEach(item => item.remove());
                    markAllButton.remove();
                    const emptyState = notificationPanel.querySelector('.notification-dropdown-empty');
                    if (!emptyState) {
                        const content = document.createElement('div');
                        content.className = 'notification-dropdown-empty';
                        content.innerHTML = '<i class="bi bi-bell" aria-hidden="true"></i><strong>You’re all caught up</strong><span>New updates will appear here.</span>';
                        notificationPanel.append(content);
                    }
                    updateUnreadCount(result.unreadCount);
                } else markAllButton.disabled = false;
            }
        });
        document.addEventListener('click', event => {
            if (!notificationMenu.contains(event.target)) closeNotifications();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !notificationPanel.hidden) {
                closeNotifications();
                notificationToggle.focus();
            }
        });
    }

    const profileMenu = document.querySelector('[data-profile-menu]');
    if (profileMenu) {
        const profileToggle = profileMenu.querySelector('[data-profile-toggle]');
        const profilePanel = profileMenu.querySelector('[data-profile-panel]');
        const profileItems = () => [...profilePanel.querySelectorAll('a[href], button:not(:disabled)')];
        const closeProfileMenu = (restoreFocus = false) => {
            profilePanel.hidden = true;
            profileToggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus) profileToggle.focus();
        };
        profileToggle.addEventListener('click', () => {
            const opening = profilePanel.hidden;
            profilePanel.hidden = !opening;
            profileToggle.setAttribute('aria-expanded', String(opening));
            if (opening) profileItems()[0]?.focus();
        });
        profilePanel.addEventListener('keydown', event => {
            const items = profileItems();
            const currentIndex = items.indexOf(document.activeElement);
            let nextIndex = currentIndex;
            if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
            else if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
            else if (event.key === 'Home') nextIndex = 0;
            else if (event.key === 'End') nextIndex = items.length - 1;
            else return;
            event.preventDefault();
            items[nextIndex]?.focus();
        });
        profilePanel.querySelectorAll('a').forEach(link => link.addEventListener('click', () => closeProfileMenu()));
        document.addEventListener('click', event => {
            if (!profileMenu.contains(event.target)) closeProfileMenu();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !profilePanel.hidden) closeProfileMenu(true);
        });
    }

    const sidebar = document.querySelector('#app-sidebar');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarBackdrop = document.querySelector('.app-sidebar-backdrop');
    if (sidebar && sidebarToggle && sidebarBackdrop) {
        const mobileSidebar = window.matchMedia('(max-width: 991.98px)');
        const closeSidebar = () => {
            sidebar.classList.remove('is-open');
            sidebarBackdrop.hidden = true;
            sidebar.inert = mobileSidebar.matches;
            sidebarToggle.setAttribute('aria-expanded', 'false');
            sidebarToggle.setAttribute('aria-label', 'Open navigation');
            document.body.classList.remove('sidebar-open');
        };
        const openSidebar = () => {
            sidebar.classList.add('is-open');
            sidebarBackdrop.hidden = false;
            sidebar.inert = false;
            sidebarToggle.setAttribute('aria-expanded', 'true');
            sidebarToggle.setAttribute('aria-label', 'Close navigation');
            document.body.classList.add('sidebar-open');
        };
        sidebar.inert = mobileSidebar.matches;
        mobileSidebar.addEventListener('change', event => {
            if (!event.matches) {
                sidebar.inert = false;
                sidebar.classList.remove('is-open');
                sidebarBackdrop.hidden = true;
                document.body.classList.remove('sidebar-open');
                sidebarToggle.setAttribute('aria-expanded', 'false');
                sidebarToggle.setAttribute('aria-label', 'Open navigation');
            } else if (!sidebar.classList.contains('is-open')) {
                sidebar.inert = true;
            }
        });
        sidebarToggle.addEventListener('click', () => sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar());
        sidebarBackdrop.addEventListener('click', closeSidebar);
        sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
            if (window.matchMedia('(max-width: 991.98px)').matches) closeSidebar();
        }));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeSidebar();
                sidebarToggle.focus();
            }
        });

        sidebar.querySelectorAll('[data-sidebar-group-toggle]').forEach(groupToggle => {
            const group = groupToggle.closest('[data-sidebar-group]');
            const groupPanel = document.getElementById(groupToggle.getAttribute('aria-controls'));
            if (!group || !groupPanel) return;
            const storageKey = `admitflow.sidebar.${group.dataset.sidebarGroup}`;
            let storedExpanded = null;
            try {
                storedExpanded = window.localStorage.getItem(storageKey);
            } catch {}
            const initiallyExpanded = group.dataset.activeGroup === 'true' || storedExpanded === 'true';
            const setExpanded = (expanded, persist = false) => {
                groupToggle.setAttribute('aria-expanded', String(expanded));
                groupPanel.hidden = !expanded;
                if (persist) {
                    try {
                        window.localStorage.setItem(storageKey, String(expanded));
                    } catch {}
                }
            };
            setExpanded(initiallyExpanded);
            groupToggle.addEventListener('click', () => {
                setExpanded(groupToggle.getAttribute('aria-expanded') !== 'true', true);
            });
        });

        const updateAnchorSelection = () => {
            const applicationsLink = sidebar.querySelector('[data-sidebar-link="Applications"]');
            sidebar.querySelectorAll('.app-sidebar-link, .app-sidebar-sublink').forEach(link => {
                link.classList.remove('is-active');
                link.removeAttribute('aria-current');
            });
            const isApplicationsAnchor = window.location.hash === '#applications';
            if (isApplicationsAnchor && applicationsLink) {
                applicationsLink.classList.add('is-active');
                applicationsLink.setAttribute('aria-current', 'location');
            } else {
                sidebar.querySelectorAll('[data-sidebar-current="true"]').forEach(link => {
                    link.classList.add('is-active');
                    link.setAttribute('aria-current', 'page');
                });
                const childLinks = [...sidebar.querySelectorAll('[data-sidebar-child]')];
                const currentLocation = window.location.pathname + window.location.hash;
                const activeChild = childLinks.find(link => link.dataset.sidebarChild === currentLocation)
                    || (window.location.hash === '' ? childLinks.find(link => new URL(link.href).pathname === window.location.pathname) : null);
                if (activeChild) {
                    activeChild.classList.add('is-active');
                    activeChild.setAttribute('aria-current', 'location');
                    const group = activeChild.closest('[data-sidebar-group]');
                    const groupToggle = group?.querySelector('[data-sidebar-group-toggle]');
                    const groupPanel = groupToggle ? document.getElementById(groupToggle.getAttribute('aria-controls')) : null;
                    if (groupToggle && groupPanel) {
                        groupToggle.classList.add('is-active');
                        groupToggle.setAttribute('aria-expanded', 'true');
                        groupPanel.hidden = false;
                    }
                }
            }
        };
        updateAnchorSelection();
        window.addEventListener('hashchange', updateAnchorSelection);
    }

    const cooldownNotice = document.querySelector('[data-login-cooldown]');
    if (cooldownNotice) {
        const countdown = cooldownNotice.querySelector('[data-cooldown-countdown]');
        const cooldownMessage = cooldownNotice.querySelector('[data-cooldown-message]');
        const submit = document.querySelector('[data-login-submit]');
        let secondsRemaining = Math.max(0, Number(cooldownNotice.dataset.loginCooldown) || 0);
        const updateCountdown = () => {
            const minutes = Math.floor(secondsRemaining / 60);
            const seconds = secondsRemaining % 60;
            const minuteLabel = `${minutes} minute${minutes === 1 ? '' : 's'}`;
            const secondLabel = `${seconds} second${seconds === 1 ? '' : 's'}`;
            if (countdown) {
                countdown.textContent = `Time remaining: ${minutes}:${String(seconds).padStart(2, '0')}`;
            }
            if (cooldownMessage) {
                cooldownMessage.textContent = `Too many failed login attempts. Please try again in ${minuteLabel} and ${secondLabel}.`;
            }
            if (secondsRemaining <= 0) {
                if (submit) submit.disabled = false;
                return;
            }
            if (submit) submit.disabled = true;
            window.setTimeout(() => {
                secondsRemaining -= 1;
                updateCountdown();
            }, 1000);
        };
        updateCountdown();
    }

    document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
        if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    }));

    const chartRoot = document.querySelector('[data-chart-data]');
    let chartData = null;
    try {
        chartData = chartRoot ? JSON.parse(chartRoot.dataset.chartData) : null;
    } catch {
        chartData = null;
    }
    if (chartData && window.Chart) {
        const palette = ['#16334A', '#E8A83E', '#6FA58D', '#D87966', '#6F95BD', '#827E73', '#A9C5D8', '#D3B56E'];
        const baseOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#263746', usePointStyle: true, boxWidth: 8 } } }, scales: { x: { ticks: { color: '#596873' }, grid: { color: '#E8E4DB' } }, y: { beginAtZero: true, ticks: { precision: 0, color: '#596873' }, grid: { color: '#E8E4DB' } } } };
        document.querySelectorAll('canvas[data-chart]').forEach(canvas => {
            const kind = canvas.dataset.chart;
            let labels = [];
            let values = [];
            let type = 'bar';
            let options = baseOptions;
            let datasets = null;
            let hasData = false;
            if (kind === 'monthly') {
                labels = chartData.monthly.map(row => row.month_key);
                values = chartData.monthly.map(row => Number(row.total));
                hasData = values.some(value => value > 0);
            } else if (kind === 'statuses') {
                labels = chartData.statuses.map(row => row.status.replaceAll('_', ' '));
                values = chartData.statuses.map(row => Number(row.total));
                type = 'doughnut';
                hasData = values.some(value => value > 0);
                options = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#263746', usePointStyle: true, boxWidth: 8 } } } };
            } else if (kind === 'programs') {
                labels = chartData.programs.map(row => row.label);
                values = chartData.programs.map(row => Number(row.total));
                hasData = values.some(value => value > 0);
                options = { ...baseOptions, indexAxis: 'y' };
            } else if (kind === 'submitted-reviewed') {
                labels = chartData.submitted_reviewed.map(row => row.month_key);
                const submitted = chartData.submitted_reviewed.map(row => Number(row.submitted));
                const reviewed = chartData.submitted_reviewed.map(row => Number(row.reviewed));
                hasData = submitted.some(value => value > 0) || reviewed.some(value => value > 0);
                datasets = [
                    { label: 'Submitted', data: submitted, backgroundColor: '#E8A83E', borderRadius: 2 },
                    { label: 'First reviewed', data: reviewed, backgroundColor: '#084884', borderRadius: 2 },
                ];
            } else if (kind === 'intake-progress') {
                labels = chartData.intake_progress.stages.map(row => row.label);
                values = chartData.intake_progress.stages.map(row => Number(row.total));
                hasData = values.some(value => value > 0);
                options = { ...baseOptions, indexAxis: 'y', plugins: { ...baseOptions.plugins, legend: { display: false }, tooltip: { callbacks: { label: context => `${context.dataset.label}: ${context.parsed.x} applications` } } }, scales: { ...baseOptions.scales, x: { ...baseOptions.scales.x, ticks: { ...baseOptions.scales.x.ticks, precision: 0 }, title: { display: true, text: 'Applications', color: '#596873' } } } };
            } else if (kind === 'review-time') {
                labels = chartData.review_time.map(row => row.month_key);
                values = chartData.review_time.map(row => row.average_hours === null ? null : Number(row.average_hours));
                hasData = values.some(value => value !== null && Number.isFinite(value));
                type = 'line';
                options = { responsive: true, maintainAspectRatio: false, spanGaps: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `${Number(context.parsed.y).toFixed(1)} hours` } } }, scales: { x: { ticks: { color: '#596873' }, grid: { color: '#E8E4DB' } }, y: { beginAtZero: true, ticks: { color: '#596873', callback: value => `${value} h` }, title: { display: true, text: 'Hours', color: '#596873' }, grid: { color: '#E8E4DB' } } } };
                datasets = [{ label: 'Average review time (hours)', data: values, borderColor: '#084884', backgroundColor: 'rgba(8,72,132,.12)', pointBackgroundColor: '#6FA58D', pointRadius: 3, borderWidth: 2, tension: .2, fill: true }];
            }
            if (!hasData) {
                canvas.parentElement.classList.add('chart-empty');
                const empty = document.createElement('p');
                empty.className = 'empty-note';
                empty.textContent = kind === 'intake-progress' && !chartData.intake_progress.intake
                    ? 'No open intake is configured.'
                    : kind === 'intake-progress'
                        ? 'No non-draft applications are recorded for this open intake yet.'
                    : kind === 'review-time'
                        ? 'No valid first-review intervals are available in these months.'
                        : 'Chart data will appear when applications are recorded.';
                canvas.replaceWith(empty);
                return;
            }
            if (datasets === null) {
                datasets = [{ label: 'Applications', data: values, backgroundColor: type === 'doughnut' ? palette : palette[0], borderColor: type === 'doughnut' ? '#FFFFFF' : palette[0], borderWidth: 1, borderRadius: type === 'bar' ? 2 : 0 }];
            }
            new Chart(canvas, { type, data: { labels, datasets }, options });
        });
    }

    const form = document.querySelector('#application-form');
    if (form) {
        const steps = [...form.querySelectorAll('[data-step]')];
        const stepLinks = [...document.querySelectorAll('[data-step-go]')];
        const wizardStep = form.querySelector('[data-wizard-step]');
        let activeStep = Number(steps.find(step => !step.hidden)?.dataset.step) || 0;
        const fieldMessages = {
            full_name: 'Please enter your full name.',
            mobile_number: 'Please enter your mobile number.',
            date_of_birth: 'Please enter your date of birth.',
            address_line: 'Please enter your house number, street, and barangay.',
            municipality: 'Please enter your city or municipality.',
            province: 'Please enter your province.',
            emergency_contact_name: 'Please enter your emergency contact name.',
            emergency_contact_number: 'Please enter your emergency contact number.',
            academic_program_id: 'Please choose an academic program.',
            academic_year_id: 'Please choose an open intake.',
            preferred_campus: 'Please enter your preferred campus.',
            previous_school: 'Please enter your previous school.',
            senior_high_strand: 'Please enter your Senior High School strand.'
        };
        form.querySelectorAll('[data-step-required]').forEach(input => input.setAttribute('aria-required', 'true'));

        const errorFor = key => form.querySelector(`[data-error-for="${CSS.escape(key)}"]`);
        const setError = (input, message) => {
            const output = errorFor(input.id || input.name);
            input.classList.toggle('is-invalid', Boolean(message));
            if (message) {
                input.setAttribute('aria-invalid', 'true');
                if (output) {
                    output.id = `${input.id || input.name}-error`;
                    input.setAttribute('aria-describedby', output.id);
                }
            } else {
                input.removeAttribute('aria-invalid');
                input.removeAttribute('aria-describedby');
            }
            if (output) {
                output.textContent = message || '';
                output.hidden = !message;
            }
        };
        const clearStepErrors = step => {
            step.querySelectorAll('.is-invalid').forEach(input => setError(input, ''));
            step.querySelectorAll('[data-step-error]').forEach(output => {
                output.textContent = '';
                output.hidden = true;
            });
        };

        const validateStep = index => {
            const step = steps[index];
            clearStepErrors(step);
            const invalid = [];
            step.querySelectorAll('[data-step-required]').forEach(input => {
                const value = input.value.trim();
                let message = '';
                if (!value) message = fieldMessages[input.id] || 'Please complete this required field.';
                else if (input.id === 'full_name' && !/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u.test(value)) message = 'Enter a valid name using letters, spaces, apostrophes, or hyphens.';
                else if (input.id === 'mobile_number' && !/^(?:09\d{9}|\+639\d{9})$/.test(value)) message = 'Enter an 11-digit mobile number starting with 09, or use +639 followed by 9 digits.';
                else if (input.id === 'date_of_birth' && (!Number.isFinite(Date.parse(value)) || new Date(value) >= new Date(new Date().toDateString()))) message = 'Enter a valid date of birth in the past.';
                else if (input.id === 'emergency_contact_name' && !/^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u.test(value)) message = 'Enter a valid emergency contact name.';
                else if (input.id === 'emergency_contact_number' && !/^[+]?[0-9(). -]{7,30}$/.test(value)) message = 'Enter a valid contact number.';
                if (message) {
                    setError(input, message);
                    invalid.push(input);
                }
            });

            if (index === 2) {
                const programId = form.querySelector('#academic_program_id').value;
                const yearId = form.querySelector('#academic_year_id').value;
                const requirements = [...step.querySelectorAll('[data-requirement-row]')].filter(row =>
                    (!row.dataset.programId || row.dataset.programId === '0' || row.dataset.programId === programId)
                    && (!row.dataset.yearId || row.dataset.yearId === '0' || row.dataset.yearId === yearId)
                );
                const stepError = step.querySelector('[data-step-error]');
                if (requirements.length === 0) {
                    if (stepError) {
                        stepError.textContent = 'No required documents are configured for this program and intake. Contact the Admission Office.';
                        stepError.hidden = false;
                    }
                }
                requirements.forEach(row => {
                    const input = row.querySelector('input[type="file"]');
                    const file = input.files[0];
                    const allowed = input.dataset.allowedExtensions.split(',').map(extension => extension.trim().toLowerCase());
                    let message = '';
                    if (file) {
                        const extension = file.name.split('.').pop().toLowerCase();
                        if (!allowed.includes(extension)) message = `Choose a ${allowed.join(', ').toUpperCase()} file.`;
                        else if (file.size < 1 || file.size > Number(input.dataset.maxBytes)) message = 'The selected file is empty or exceeds the size limit shown above.';
                    } else if (!input.dataset.existingStatus || input.dataset.existingStatus === 'rejected') {
                        message = `Upload ${row.querySelector('label').textContent.replace('*', '').trim()}.`;
                    }
                    if (message) {
                        setError(input, message);
                        invalid.push(input);
                    }
                });
            }

            return invalid;
        };

        const updateSummary = () => {
            ['full_name', 'mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number', 'preferred_campus', 'previous_school', 'senior_high_strand'].forEach(id => {
                const output = form.querySelector(`[data-summary="${id}"]`);
                const input = form.querySelector(`#${id}`);
                if (output && input) output.textContent = input.value.trim() || 'Not entered';
            });
            ['academic_program_id', 'academic_year_id'].forEach(id => {
                const input = form.querySelector(`#${id}`);
                const output = form.querySelector(`[data-summary="${id}"]`);
                if (output) output.textContent = input.selectedOptions[0]?.value ? input.selectedOptions[0].textContent : 'Not selected';
            });
            form.querySelectorAll('[data-summary-document]').forEach(row => {
                const input = form.querySelector(`#document_${row.dataset.summaryDocument}`);
                const output = row.querySelector('[data-document-summary]');
                if (input.files[0]) output.textContent = `${input.files[0].name} · selected, awaiting server validation`;
                else if (input.dataset.existingStatus) output.textContent = input.dataset.existingStatus === 'verified' ? 'Verified by staff' : `Uploaded · ${input.dataset.existingStatus.replaceAll('_', ' ')} (not yet verified)`;
                else output.textContent = 'Missing';
            });
            const selectedProgramId = form.querySelector('#academic_program_id').value;
            const selectedYearId = form.querySelector('#academic_year_id').value;
            form.querySelectorAll('[data-requirement-row], [data-summary-document]').forEach(row => {
                const matchesProgram = !row.dataset.programId || row.dataset.programId === '0' || row.dataset.programId === selectedProgramId;
                const matchesYear = !row.dataset.yearId || row.dataset.yearId === '0' || row.dataset.yearId === selectedYearId;
                row.hidden = !matchesProgram || !matchesYear;
            });
        };

        const showStep = (index, moveFocus = true) => {
            activeStep = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach((step, stepIndex) => {
                step.hidden = stepIndex !== activeStep;
                step.classList.toggle('is-visible', stepIndex === activeStep);
            });
            stepLinks.forEach((link, linkIndex) => {
                link.classList.toggle('is-active', linkIndex === activeStep);
                link.classList.toggle('is-complete', linkIndex < activeStep);
                if (linkIndex === activeStep) link.setAttribute('aria-current', 'step');
                else link.removeAttribute('aria-current');
            });
            if (activeStep === steps.length - 1) updateSummary();
            if (moveFocus) steps[activeStep].querySelector('h2')?.focus({ preventScroll: true });
            steps[activeStep].scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        form.addEventListener('click', event => {
            if (event.target.closest('[data-step-back]')) showStep(activeStep - 1);
        });
        stepLinks.forEach(link => link.addEventListener('click', () => {
            const target = Number(link.dataset.stepGo);
            if (target <= activeStep) {
                showStep(target);
                return;
            }
            const invalid = validateStep(activeStep);
            if (invalid.length) {
                invalid[0].focus();
                return;
            }
            const nextButton = steps[activeStep].querySelector('[data-step-next]');
            if (nextButton) {
                wizardStep.value = String(activeStep);
                form.requestSubmit(nextButton);
            }
        }));
        form.addEventListener('submit', event => {
            const action = event.submitter?.value;
            if (action === 'step') {
                const sourceStep = Number(event.submitter.dataset.nextStep);
                for (let index = 0; index <= sourceStep; index += 1) {
                    const invalid = validateStep(index);
                    if (invalid.length) {
                        event.preventDefault();
                        showStep(index);
                        invalid[0].focus();
                        return;
                    }
                }
                wizardStep.value = String(sourceStep);
            } else if (action === 'submit') {
                for (let index = 0; index <= 2; index += 1) {
                    const invalid = validateStep(index);
                    if (invalid.length) {
                        event.preventDefault();
                        showStep(index);
                        invalid[0].focus();
                        return;
                    }
                }
                const consent = form.querySelector('[name="privacy_consent"]');
                if (!consent.checked) {
                    event.preventDefault();
                    const output = errorFor('privacy_consent');
                    output.textContent = 'Please confirm the privacy notice before submitting.';
                    output.hidden = false;
                    showStep(3);
                    consent.focus();
                    return;
                }
                wizardStep.value = '3';
            }
        });
        form.addEventListener('change', updateSummary);
        form.addEventListener('input', updateSummary);
        showStep(activeStep, false);
        updateSummary();
        const serverError = form.querySelector('.field-error:not([hidden])');
        if (serverError) {
            const fieldId = serverError.dataset.errorFor;
            const invalidField = fieldId ? form.querySelector(`#${CSS.escape(fieldId)}`) : null;
            if (invalidField) {
                invalidField.classList.add('is-invalid');
                invalidField.setAttribute('aria-invalid', 'true');
                invalidField.setAttribute('aria-describedby', serverError.id || `${fieldId}-error`);
                serverError.id = serverError.id || `${fieldId}-error`;
                invalidField.focus();
            }
        }
    }

    const previewDialog = document.querySelector('[data-document-preview]');
    if (previewDialog) {
        const previewTitle = previewDialog.querySelector('[data-preview-title]');
        const previewContent = previewDialog.querySelector('[data-preview-content]');
        const closePreview = previewDialog.querySelector('[data-preview-close]');
        let activePreviewUrl = null;

        const clearPreview = () => {
            if (activePreviewUrl) URL.revokeObjectURL(activePreviewUrl);
            activePreviewUrl = null;
            previewContent.replaceChildren();
        };
        const showPreviewMessage = message => {
            clearPreview();
            const paragraph = document.createElement('p');
            paragraph.className = 'document-preview-message';
            paragraph.textContent = message;
            previewContent.append(paragraph);
        };
        const previewDocument = async button => {
            previewTitle.textContent = button.dataset.documentName || 'Document preview';
            previewDialog.showModal();
            showPreviewMessage('Loading secure preview…');
            const previewUrl = button.dataset.previewUrl;
            const downloadUrl = button.dataset.downloadUrl;
            const extension = previewTitle.textContent.split('.').pop().toLowerCase();
            if (!['pdf', 'jpg', 'jpeg', 'png'].includes(extension)) {
                clearPreview();
                const message = document.createElement('p');
                message.className = 'document-preview-message';
                message.textContent = 'This file format cannot be previewed here. Use the authorized download action to open it.';
                previewContent.append(message);
                if (downloadUrl) {
                    const download = document.createElement('a');
                    download.className = 'btn btn-outline-navy';
                    download.href = downloadUrl;
                    download.innerHTML = '<i class="bi bi-download" aria-hidden="true"></i> Download document';
                    previewContent.append(download);
                }
                return;
            }

            try {
                const response = await fetch(previewUrl, { credentials: 'same-origin', cache: 'no-store' });
                if (!response.ok) {
                    const detail = (await response.text()).trim();
                    showPreviewMessage(detail || 'This document is unavailable or cannot be previewed. Use the authorized download action when available.');
                    if (downloadUrl) {
                        const download = document.createElement('a');
                        download.className = 'btn btn-outline-navy';
                        download.href = downloadUrl;
                        download.textContent = 'Download document';
                        previewContent.append(download);
                    }
                    return;
                }
                const contentType = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
                const expectedType = extension === 'pdf' ? 'application/pdf' : extension === 'png' ? 'image/png' : 'image/jpeg';
                if (contentType !== expectedType) {
                    showPreviewMessage('The document content does not match its file type, so it cannot be previewed safely.');
                    return;
                }
                const blob = await response.blob();
                if (!blob.size) {
                    showPreviewMessage('This document is empty and cannot be previewed.');
                    return;
                }
                activePreviewUrl = URL.createObjectURL(blob);
                const content = extension === 'pdf' ? document.createElement('iframe') : document.createElement('img');
                if (extension === 'pdf') {
                    content.title = `PDF preview: ${previewTitle.textContent}`;
                    content.src = activePreviewUrl;
                } else {
                    content.alt = `Preview of ${previewTitle.textContent}`;
                    content.src = activePreviewUrl;
                }
                content.className = 'document-preview-media';
                previewContent.replaceChildren(content);
                if (content.tagName === 'IMG') {
                    content.addEventListener('error', () => showPreviewMessage('This image is damaged or cannot be displayed. Use the authorized download action when available.'), { once: true });
                }
            } catch {
                showPreviewMessage('The document preview is unavailable right now. Try the authorized download action.');
            }
        };

        document.querySelectorAll('[data-quick-view]:not(:disabled)').forEach(button => button.addEventListener('click', () => previewDocument(button)));
        closePreview.addEventListener('click', () => previewDialog.close());
        previewDialog.addEventListener('close', clearPreview);
        previewDialog.addEventListener('click', event => {
            if (event.target === previewDialog) previewDialog.close();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && previewDialog.open) previewDialog.close();
        });
    }

    document.querySelectorAll('[data-password-toggle]').forEach(passwordToggle => {
        const password = passwordToggle.closest('.input-group')?.querySelector('input[type="password"], input[type="text"]');
        if (!password) return;
        passwordToggle.addEventListener('click', () => {
            const visible = password.type === 'password';
            password.type = visible ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            passwordToggle.setAttribute('aria-pressed', String(visible));
            passwordToggle.querySelector('i')?.classList.toggle('bi-eye', !visible);
            passwordToggle.querySelector('i')?.classList.toggle('bi-eye-slash', visible);
        });
    });

    document.querySelectorAll('[data-password-checklist]').forEach(form => {
        const newPassword = form.querySelector('[data-password-new]');
        const confirmation = form.querySelector('[data-password-confirmation]');
        const checklist = form.querySelector('#password-checklist');
        const strengthPanel = form.querySelector('[data-password-strength-panel]');
        if (!newPassword || !checklist) return;

        const updateRule = (name, evaluated, met) => {
            const row = checklist.querySelector(`[data-password-rule="${name}"]`);
            const state = row?.querySelector('[data-rule-state]');
            if (!row || !state) return;
            row.classList.toggle('is-met', evaluated && met);
            row.classList.toggle('is-unmet', evaluated && !met);
            state.textContent = evaluated ? (met ? 'Met' : 'Not met') : 'Not checked';
        };
        const normalizeNfkc = value => {
            try {
                return value.normalize('NFKC');
            } catch {
                return value;
            }
        };
        const normalize = value => normalizeNfkc(value).toLocaleLowerCase();
        const nameInput = form.querySelector('[name="full_name"], [name="name"]');
        const emailInput = form.querySelector('[name="email"]');
        const clampPassword = input => {
            const maxCharacters = Number(input.dataset.maxCharacters) || 64;
            const maxUtf16Length = Number(input.maxLength);
            const characters = Array.from(input.value);
            if (characters.length <= maxCharacters && (maxUtf16Length < 0 || input.value.length <= maxUtf16Length)) return;
            let result = '';
            let count = 0;
            for (const character of characters) {
                if (count >= maxCharacters || (maxUtf16Length > 0 && result.length + character.length > maxUtf16Length)) break;
                result += character;
                count++;
            }
            input.value = result;
        };
        const updateChecklist = () => {
            clampPassword(newPassword);
            if (confirmation) clampPassword(confirmation);
            const value = newPassword.value;
            const normalizedValue = normalizeNfkc(value);
            const characters = Array.from(normalizedValue);
            const hasValue = value.length > 0;
            const lengthValid = characters.length >= 12 && characters.length <= 64 && new TextEncoder().encode(normalizedValue).length <= 72;
            const categories = [/\p{Ll}/u.test(normalizedValue), /\p{Lu}/u.test(normalizedValue), /\p{N}/u.test(normalizedValue), /[\p{P}\p{S}]/u.test(normalizedValue)];
            const varietyValid = characters.length >= 16 || categories.filter(Boolean).length >= 3;
            const nameHint = nameInput?.value || form.dataset.passwordNameHint || '';
            const emailHint = emailInput?.value || form.dataset.passwordEmailHint || '';
            const emailLocalPart = emailHint.split('@', 1)[0];
            const nameParts = nameHint.split(/[^\p{L}\p{M}]+/u).filter(part => Array.from(part).length >= 4);
            const emailParts = emailLocalPart.split(/[^\p{L}\p{M}\p{N}]+/u).filter(part => Array.from(part).length >= 4);
            const meaningful = hint => Array.from(normalize(hint).replace(/[^\p{L}\p{M}\p{N}]/gu, '')).length >= 4;
            const hints = [nameHint, emailLocalPart, ...nameParts, ...emailParts].filter(meaningful).map(normalize);
            const personalValid = !hints.some(hint => normalize(value).includes(hint));
            const matchEvaluated = Boolean(confirmation?.value || value);
            const matchValid = Boolean(confirmation?.value) && normalizedValue === normalizeNfkc(confirmation.value);

            updateRule('length', hasValue, lengthValid);
            updateRule('variety', hasValue, varietyValid);
            updateRule('personal', hasValue, personalValid);
            updateRule('match', matchEvaluated, matchValid);

            if (!strengthPanel) return;
            const strong = lengthValid && varietyValid && personalValid && matchValid && characters.length >= 16;
            const fair = lengthValid && varietyValid && personalValid && matchValid;
            const level = !hasValue ? 'empty' : strong ? 'strong' : fair ? 'fair' : 'weak';
            strengthPanel.dataset.state = level;
            const meter = strengthPanel.querySelector('[data-password-strength-meter]');
            const status = strengthPanel.querySelector('[data-password-strength-status]');
            const score = level === 'strong' ? 3 : level === 'fair' ? 2 : level === 'weak' ? 1 : 0;
            meter?.parentElement?.setAttribute('aria-valuenow', String(score));
            if (status) status.textContent = level === 'empty' ? 'Strength: not checked' : `Preliminary strength: ${level[0].toUpperCase()}${level.slice(1)}`;
        };

        newPassword.addEventListener('input', updateChecklist);
        confirmation?.addEventListener('input', updateChecklist);
        nameInput?.addEventListener('input', updateChecklist);
        emailInput?.addEventListener('input', updateChecklist);
        updateChecklist();
    });
})();