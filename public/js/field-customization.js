(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('fieldCustomizationForm');
        if (!form) {
            return;
        }

        const tableBody = document.getElementById('fieldCustomizationTableBody');
        const moduleKeyInput = document.getElementById('fieldCustomCurrentModule');
        const activeNameEl = document.getElementById('fieldCustomActiveModuleName');
        const activeKeyEl = document.getElementById('fieldCustomActiveModuleKey');
        const activeDescEl = document.getElementById('fieldCustomActiveModuleDesc');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function showNotification(type, message) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: type,
                    title: message,
                    showConfirmButton: false,
                    timer: 3500,
                    timerProgressBar: true
                });
            } else if (typeof window.showAlert === 'function') {
                window.showAlert(type, message);
            } else {
                alert(message);
            }
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderRows(fields) {
            if (!tableBody) return;
            tableBody.innerHTML = '';

            fields.forEach(function (field) {
                const fname = field.field_name;
                const isSystem = Boolean(field.system_required);
                const customLabel = field.custom_label || '';
                const defaultLabel = field.default_label || '';
                const validationMsg = field.validation_message || '';
                const displayOrder = field.display_order ?? 0;
                const isVisible = Boolean(field.is_visible);
                const isRequired = Boolean(field.is_required);

                const tr = document.createElement('tr');
                tr.setAttribute('data-field-name', fname);
                tr.id = 'field-row-' + fname;

                tr.innerHTML = `
                    <td>
                        <span class="text-dark fw-semibold small default-label-text">${escapeHtml(defaultLabel)}</span>
                    </td>
                    <td>
                        <div class="input-group input-group-sm">
                            <input type="text" 
                                name="fields[${fname}][custom_label]" 
                                class="form-control field-custom-label-input" 
                                value="${escapeHtml(customLabel)}" 
                                placeholder="${escapeHtml(defaultLabel)}"
                                data-default="${escapeHtml(defaultLabel)}"
                                title="Change display label">
                            <button type="button" 
                                class="btn btn-outline-secondary btn-restore-default-label" 
                                title="Restore default label: ${escapeHtml(defaultLabel)}">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </div>
                    </td>
                    <input type="hidden" name="fields[${fname}][is_visible]" value="${isVisible ? '1' : '0'}">
                    <td class="text-center">
                        <div class="position-relative d-inline-flex align-items-center justify-content-center">
                            ${isSystem ? `
                                <input type="hidden" name="fields[${fname}][is_required]" value="1">
                                <div class="form-check form-switch d-inline-block m-0" title="System field must remain required">
                                    <input class="form-check-input" type="checkbox" role="switch" checked disabled>
                                </div>
                                <i class="fa-solid fa-lock text-secondary position-absolute" 
                                    style="left: calc(100% + 6px); top: 50%; transform: translateY(-50%); font-size: 0.8rem;" 
                                    title="System required"></i>
                            ` : `
                                <input type="hidden" name="fields[${fname}][is_required]" value="0">
                                <div class="form-check form-switch d-inline-block m-0">
                                    <input class="form-check-input field-required-switch" 
                                        type="checkbox" 
                                        role="switch" 
                                        name="fields[${fname}][is_required]" 
                                        value="1" 
                                        ${isRequired ? 'checked' : ''}
                                        title="Toggle required status">
                                </div>
                            `}
                        </div>
                        <input type="hidden" name="fields[${fname}][display_order]" value="${displayOrder}">
                    </td>
                    <td>
                        <input type="text" 
                            name="fields[${fname}][validation_message]" 
                            class="form-control form-control-sm field-validation-message-input" 
                            value="${escapeHtml(validationMsg)}" 
                            placeholder="e.g. ${escapeHtml(field.label || defaultLabel)} is required.">
                    </td>
                `;

                tableBody.appendChild(tr);
            });
        }

        // Module Scroller navigation & wheel scroll
        const modScroller = document.getElementById('fieldCustomizationModulePills');
        const scrollBtnLeft = document.getElementById('fieldModScrollLeft');
        const scrollBtnRight = document.getElementById('fieldModScrollRight');

        function updateScrollNavState() {
            if (!modScroller || !scrollBtnLeft || !scrollBtnRight) return;
            const maxScroll = modScroller.scrollWidth - modScroller.clientWidth;
            if (maxScroll <= 6) {
                scrollBtnLeft.disabled = true;
                scrollBtnRight.disabled = true;
                scrollBtnLeft.style.opacity = '0.35';
                scrollBtnRight.style.opacity = '0.35';
                return;
            }
            const canScrollLeft = modScroller.scrollLeft > 6;
            const canScrollRight = modScroller.scrollLeft < maxScroll - 6;
            scrollBtnLeft.disabled = !canScrollLeft;
            scrollBtnRight.disabled = !canScrollRight;
            scrollBtnLeft.style.opacity = canScrollLeft ? '1' : '0.35';
            scrollBtnRight.style.opacity = canScrollRight ? '1' : '0.35';
        }

        if (scrollBtnLeft && modScroller) {
            scrollBtnLeft.addEventListener('click', function () {
                modScroller.scrollBy({ left: -260, behavior: 'smooth' });
            });
        }
        if (scrollBtnRight && modScroller) {
            scrollBtnRight.addEventListener('click', function () {
                modScroller.scrollBy({ left: 260, behavior: 'smooth' });
            });
        }
        if (modScroller) {
            modScroller.addEventListener('scroll', updateScrollNavState, { passive: true });
            window.addEventListener('resize', updateScrollNavState);
            modScroller.addEventListener('wheel', function (e) {
                if (e.deltaY !== 0 && modScroller.scrollWidth > modScroller.clientWidth) {
                    e.preventDefault();
                    modScroller.scrollLeft += e.deltaY;
                }
            }, { passive: false });
            setTimeout(updateScrollNavState, 150);
        }

        // Module pill selection click
        document.querySelectorAll('.field-custom-mod-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const module = this.dataset.module;
                if (!module || module === moduleKeyInput.value) {
                    return;
                }

                document.querySelectorAll('.field-custom-mod-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                this.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });

                loadModule(module);
            });
        });

        function loadModule(module) {
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Loading configurable fields for ${escapeHtml(module)}...
                        </td>
                    </tr>
                `;
            }

            fetch(`/settings/field-customization/${encodeURIComponent(module)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    showNotification('error', data.message || 'Failed to load fields.');
                    return;
                }

                moduleKeyInput.value = module;
                if (activeNameEl) activeNameEl.textContent = data.module.name;
                if (activeKeyEl) activeKeyEl.textContent = data.module.key;
                if (activeDescEl) activeDescEl.textContent = data.module.description;

                renderRows(data.fields);
            })
            .catch(function (err) {
                showNotification('error', 'Error loading module fields: ' + err.message);
            });
        }

        // Restore single field default label
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-restore-default-label');
            if (!btn) return;

            const row = btn.closest('tr');
            if (!row) return;

            const input = row.querySelector('.field-custom-label-input');
            if (input) {
                input.value = input.dataset.default || '';
                input.focus();
            }
        });

        // Form Submit via AJAX
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const module = moduleKeyInput.value;
            const submitBtn = document.getElementById('btnSaveFieldCustomizationBottom');
            const submitLabel = document.getElementById('btnSaveFieldCustomLabel');

            if (submitBtn) submitBtn.disabled = true;
            if (submitLabel) submitLabel.textContent = 'Saving...';

            const formData = new FormData(form);

            fetch(`/settings/field-customization/${encodeURIComponent(module)}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (submitBtn) submitBtn.disabled = false;
                if (submitLabel) submitLabel.textContent = 'Save Changes';

                if (data.success) {
                    showNotification('success', data.message);
                    if (data.fields) {
                        renderRows(data.fields);
                    }
                } else {
                    showNotification('error', data.message || 'Validation error while saving.');
                }
            })
            .catch(function (err) {
                if (submitBtn) submitBtn.disabled = false;
                if (submitLabel) submitLabel.textContent = 'Save Changes';
                showNotification('error', 'An error occurred while saving: ' + err.message);
            });
        });

        // Reset to Default button
        const resetBtn = document.getElementById('btnResetFieldCustomizationBottom');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                const module = moduleKeyInput.value;
                const moduleName = activeNameEl ? activeNameEl.textContent : module;

                const doReset = function () {
                    resetBtn.disabled = true;

                    fetch(`/settings/field-customization/${encodeURIComponent(module)}/reset`, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        resetBtn.disabled = false;
                        if (data.success) {
                            showNotification('success', data.message);
                            if (data.fields) {
                                renderRows(data.fields);
                            }
                        } else {
                            showNotification('error', data.message || 'Failed to reset fields.');
                        }
                    })
                    .catch(function (err) {
                        resetBtn.disabled = false;
                        showNotification('error', 'Error resetting fields: ' + err.message);
                    });
                };

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Reset to Defaults?',
                        text: `This will clear all custom labels, mandatory rules, and custom error messages for ${moduleName}.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: 'Yes, reset to default'
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            doReset();
                        }
                    });
                } else if (confirm(`Reset all customizations for ${moduleName} to system defaults?`)) {
                    doReset();
                }
            });
        }
    });
})();

