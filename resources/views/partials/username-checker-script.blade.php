<script>
(function() {
    'use strict';

    function initUsernameCheckers() {
        // Never run on login, auth, or guest pages
        if (document.querySelector('#login-form, .login-page, form[action*="login"]')) {
            return;
        }

        const usernameInputs = document.querySelectorAll('input[name="username"], input#username, input[data-check-username="true"]');
        
        usernameInputs.forEach(function(input) {
            // Ignore hidden fake autofill inputs and login inputs
            if (input.type === 'hidden' || input.style.position === 'absolute' || input.classList.contains('d-none')) {
                return;
            }
            if (input.closest('#login-form, .login-page, form[action*="login"], .auth-card')) {
                return;
            }

            if (input.dataset.usernameCheckerBound) {
                return;
            }
            input.dataset.usernameCheckerBound = 'true';

            // Locate or create feedback container
            let container = input.parentElement.querySelector('.username-availability-feedback');
            if (!container) {
                container = document.createElement('div');
                container.className = 'username-availability-feedback mt-1';
                // Insert after input or after parent input-group
                if (input.closest('.input-group')) {
                    input.closest('.input-group').insertAdjacentElement('afterend', container);
                } else {
                    input.insertAdjacentElement('afterend', container);
                }
            }

            let debounceTimer = null;
            let currentXhr = null;

            function validateUsername(isChange) {
                const val = (input.value || '').trim();
                const originalVal = (input.dataset.originalValue || '').trim();
                const ignoreUserId = input.dataset.ignoreUserId || '';
                const ignoreCustomerId = input.dataset.ignoreCustomerId || '';
                const context = input.dataset.context || '';
                const isRequired = input.hasAttribute('required');

                if (currentXhr) {
                    currentXhr.abort();
                    currentXhr = null;
                }

                if (!val) {
                    if (isRequired) {
                        input.classList.remove('is-valid');
                        input.classList.add('is-invalid');
                        container.innerHTML = '<small class="text-danger fw-semibold"><i class="fas fa-exclamation-circle me-1"></i> Username is required</small>';
                    } else {
                        input.classList.remove('is-valid', 'is-invalid');
                        container.innerHTML = '';
                    }
                    return;
                }

                // If editing and value matches original value
                if (originalVal && val.toLowerCase() === originalVal.toLowerCase()) {
                    input.classList.remove('is-invalid');
                    input.classList.add('is-valid');
                    container.innerHTML = '<small class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> Current username (available)</small>';
                    return;
                }

                if (val.length < 3) {
                    input.classList.remove('is-valid');
                    input.classList.add('is-invalid');
                    container.innerHTML = '<small class="text-danger fw-semibold"><i class="fas fa-exclamation-circle me-1"></i> Username must be at least 3 characters</small>';
                    return;
                }

                // Show checking status
                container.innerHTML = '<small class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i> Checking availability in CRM & Central Portal...</small>';

                const tokenMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

                const postData = {
                    username: val,
                    ignore_user_id: ignoreUserId,
                    ignore_customer_id: ignoreCustomerId,
                    context: context
                };

                const controller = new AbortController();
                currentXhr = controller;

                fetch('{{ url("/check-username") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify(postData),
                    signal: controller.signal
                })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    currentXhr = null;
                    if (data.available) {
                        input.classList.remove('is-invalid');
                        input.classList.add('is-valid');
                        container.innerHTML = '<small class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> Username is available</small>';
                    } else {
                        input.classList.remove('is-valid');
                        input.classList.add('is-invalid');
                        const msg = data.message || 'Username is already taken';
                        container.innerHTML = '<small class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i> ' + msg + '</small>';
                    }
                })
                .catch(function(err) {
                    if (err.name === 'AbortError') return;
                    currentXhr = null;
                    // Fallback to GET check if POST had CSRF issue
                    fetch('{{ url("/check-username") }}?username=' + encodeURIComponent(val) + '&ignore_user_id=' + encodeURIComponent(ignoreUserId) + '&ignore_customer_id=' + encodeURIComponent(ignoreCustomerId) + '&context=' + encodeURIComponent(context), {
                        headers: { 'Accept': 'application/json' }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.available) {
                            input.classList.remove('is-invalid');
                            input.classList.add('is-valid');
                            container.innerHTML = '<small class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> Username is available</small>';
                        } else {
                            input.classList.remove('is-valid');
                            input.classList.add('is-invalid');
                            container.innerHTML = '<small class="text-danger fw-semibold"><i class="fas fa-times-circle me-1"></i> ' + (data.message || 'Username is already taken') + '</small>';
                        }
                    })
                    .catch(() => {
                        container.innerHTML = '';
                    });
                });
            }

            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    validateUsername(false);
                }, 300);
            });

            input.addEventListener('change', function() {
                clearTimeout(debounceTimer);
                validateUsername(true);
            });

            // Prevent form submit if username is invalid/taken
            const form = input.closest('form');
            if (form && !form.dataset.usernameCheckGuarded) {
                form.dataset.usernameCheckGuarded = 'true';
                form.addEventListener('submit', function(e) {
                    if (input.classList.contains('is-invalid')) {
                        e.preventDefault();
                        e.stopPropagation();
                        input.focus();
                        if (typeof window.toastr !== 'undefined') {
                            toastr.error('Please choose an available username before submitting.');
                        } else if (typeof window.Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Username Unavailable',
                                text: 'The entered username is already taken. Please choose another username.',
                                confirmButtonColor: '#0d6efd'
                            });
                        } else {
                            alert('The entered username is already taken. Please choose an available username.');
                        }
                        return false;
                    }
                }, true);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUsernameCheckers);
    } else {
        initUsernameCheckers();
    }

    // Re-check on dynamic modal opens or tab changes
    document.addEventListener('shown.bs.modal', initUsernameCheckers);
    document.addEventListener('shown.bs.tab', initUsernameCheckers);
})();
</script>

