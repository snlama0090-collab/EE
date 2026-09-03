/**
 * auth.js — OTP verification flow for registration.
 *
 * Null-guards on every DOM lookup so missing elements never crash the page.
 */

(function () {
    'use strict';

    // ── state ──
    var pendingEmail = '';
    var pendingUserType = 'driver';
    var pendingFormData = null;

    // ── DOM refs (null-safe) ──
    var $ = function (id) { var el = document.getElementById(id); return el; };

    var registerForm    = $('register-form');
    var otpModal        = $('otpModal');
    var otpInput        = $('otp-input');
    var otpSubmitBtn    = $('otp-submit-btn');
    var otpResendBtn    = $('otp-resend-btn');
    var otpError        = $('otp-error');
    var otpEmailDisplay = $('otp-email-display');
    var submitBtn       = $('submit-btn');

    // ── live password checklist (length only; mirrors server rules) ──
    var pwEl = $('password');
    if (pwEl) {
        var pwBox = $('pw-checklist');
        var ruleEls = { len: $('pw-rule-len') };
        var cfg = window.PW_CONFIG || { min: 8 };
        var repaintPw = function () {
            var v = pwEl.value;
            var states = [
                [v.length >= cfg.min, ruleEls.len]
            ];
            states.forEach(function (p) { if (p[1]) p[1].style.color = p[0] ? '#22c55e' : ''; });
        };
        pwEl.addEventListener('focus', function () { if (pwBox) pwBox.style.display = 'block'; });
        pwEl.addEventListener('input', function () { if (pwBox) pwBox.style.display = 'block'; repaintPw(); updateStrength(); });
    }

    // ── password strength indicator (informational only) ──
    function scoreStrength(pw) {
        if (!pw) return 0;
        var score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (pw.length >= 16) score++;
        if (/[a-z]/.test(pw)) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return score;
    }

    function updateStrength() {
        var pw = $('password');
        var el = $('pw-strength');
        if (!pw || !el) return;
        var s = scoreStrength(pw.value);
        var label, color;
        if (pw.value.length === 0) { label = ''; color = ''; }
        else if (s <= 2) { label = 'Weak'; color = '#e5484d'; }
        else if (s <= 4) { label = 'Fair'; color = '#f5a623'; }
        else if (s <= 5) { label = 'Strong'; color = '#22c55e'; }
        else { label = 'Very Strong'; color = '#16a34a'; }
        el.textContent = label;
        el.style.color = color;
        el.style.fontWeight = '600';
    }

    // ── unified declarative validation engine (shared by both auth pages) ──
    // Forms carry `novalidate`: THIS layer is the client gate; the server remains
    // the security boundary. Rules mirror api/auth/register.php exactly.
    var Validation = (function () {
        var style = document.createElement('style');
        style.textContent =
            '.field-error{color:#e5484d;font-size:12px;margin-top:4px;}' +
            '.is-invalid{border-color:#e5484d !important;}';
        document.head.appendChild(style);

        var RE = {
            email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
            gmail: /^[a-zA-Z0-9._%+-]+@gmail\.com$/i,
            phone: /^(?:\+977\s?)?9[78]\d{8}$/,
            bank: /^[0-9]{5,20}$/
        };
        var boundForms = {};

        function hostFor(input) {
            return input.closest('.form-group') || input.parentNode;
        }
        function setError(input, msg) {
            input.classList.add('is-invalid');
            var host = hostFor(input);
            var err = host.querySelector('.field-error[data-for="' + input.id + '"]');
            if (!err) {
                err = document.createElement('div');
                err.className = 'field-error';
                err.setAttribute('data-for', input.id);
                host.appendChild(err);
            }
            err.textContent = msg;
        }
        function clearError(input) {
            input.classList.remove('is-invalid');
            var host = hostFor(input);
            var err = host.querySelector('.field-error[data-for="' + input.id + '"]');
            if (err) err.textContent = '';
        }
        function getV(id) {
            var el = document.getElementById(id);
            return el ? el.value : '';
        }

        /**
         * Bind a rule table to a form.
         * rules: [{ id, when?(getV)->bool, checks: [[test(value)->bool, msg], ...] }]
         * Returns validateAll() -> bool (focuses + scrolls to first offender).
         */
        function bindRules(formId, rules) {
            var form = document.getElementById(formId);
            if (!form || boundForms[formId]) return null;
            boundForms[formId] = true;
            if (!window.__VLOG) window.__VLOG = [];
            window.__VLOG.push('BIND ' + formId + ' utype=' + JSON.stringify(getV('user_type')));
            var touched = {};

            function runOne(rule) {
                var el = document.getElementById(rule.id);
                if (!el || el.disabled) { clearError(el); return null; } // N/A fields carry no error
                if (rule.when && !rule.when(getV)) { clearError(el); return null; }
                var v = el.type === 'checkbox' ? el.checked : el.value;
                for (var i = 0; i < rule.checks.length; i++) {
                    if (!rule.checks[i][0](v)) {
                        setError(el, rule.checks[i][1]);
                        return el;
                    }
                }
                clearError(el);
                return null;
            }
            function validateAll() {
                var first = null;
                rules.forEach(function (r) {
                    var bad = runOne(r);
                    if (bad && !first) first = bad;
                });
                rules.forEach(function (r) {
                    var el = document.getElementById(r.id);
                    if (el) touched[el.id] = true; // keep live-updating after an attempt
                });
                if (first) {
                    first.focus();
                    if (first.scrollIntoView) first.scrollIntoView({ block: 'center' });
                }
                return first === null;
            }
            rules.forEach(function (r) {
                var el = document.getElementById(r.id);
                if (!el) return;
                el.addEventListener('blur', function () { touched[el.id] = true; /* ponytail: empty-pristine blur stays silent - required-errors wait for submit */ if ((el.type === 'checkbox' ? !!el.checked : el.value.trim()) !== '') runOne(r); });
                el.addEventListener('input', function () { if (touched[el.id]) runOne(r); });
                if (el.type === 'checkbox') {
                    el.addEventListener('change', function () { touched[el.id] = true; runOne(r); });
                }
            });
            return validateAll;
        }

        return { RE: RE, bindRules: bindRules };
    })();

    // ── show / hide modal ──
    function showOtpModal() {
        if (!otpModal) return;
        otpModal.style.display = 'flex';
        if (otpInput) { otpInput.value = ''; otpInput.focus(); }
        if (otpError) otpError.textContent = '';
        if (otpSubmitBtn) { otpSubmitBtn.disabled = false; otpSubmitBtn.textContent = 'Complete Registration'; }
    }

    function hideOtpModal() {
        if (!otpModal) return;
        otpModal.style.display = 'none';
    }

    // ── Gmail-only regex ──
    var GMAIL_RE = /^[a-zA-Z0-9._%+-]+@gmail\.com$/i;

    // ── safe JSON parse: never throw on non-JSON (e.g. raw PHP fatal) ──
    function parseJson(r) {
        return r.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                // Surface the real HTTP status instead of a generic "network error"
                return { status: 'error', message: 'Server returned an invalid response (HTTP ' + r.status + ').' };
            }
        });
    }

    // ── send OTP ──
    function sendOtp(email) {
        return fetch('/EE/api/auth/otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_otp', email: email })
        }).then(parseJson);
    }

    // ── verify OTP ──
    function verifyOtp(email, otp) {
        return fetch('/EE/api/auth/otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify_otp', email: email, otp: otp })
        }).then(parseJson);
    }

    // ── final registration call (JSON only - picture selection moved to the
    // post-registration profile-picture.php step for both signup flows) ──
    function completeRegistration(data) {
        return fetch('/EE/api/auth/register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(parseJson);
    }

    // ── declarative rule tables + bindings ──
    var pwMin = (window.PW_CONFIG && window.PW_CONFIG.min) || 8;

    var isDriver = function (getV) { return getV('user-type') === 'driver'; };
    var isOwner = function (getV) { return getV('user-type') === 'owner'; };

    var nameChecks = [
        [function (v) { return v.trim().length >= 2 && v.trim().length <= 100; }, 'Name must be between 2 and 100 characters'],
        [function (v) { return (v.match(/[A-Za-z\u00C0-\u024F]/g) || []).length >= 2; }, 'Please enter your real name']
    ];
    var emailGmailChecks = [
        [function (v) { return v.trim() !== ''; }, 'Email address is required'],
        [function (v) { return Validation.RE.gmail.test(v.trim()); }, 'Only @gmail.com addresses are allowed.']
    ];
    var phoneChecks = [
        [function (v) { return v.trim() !== ''; }, 'Phone number is required'],
        [function (v) { return Validation.RE.phone.test(v.trim()); }, 'Enter a valid Nepali phone number (e.g., +977 98XXXXXXXX)']
    ];

    window.__loginValidate = Validation.bindRules('login-form', [
        { id: 'email', checks: [
            [function (v) { return v.trim() !== ''; }, 'Email address is required'],
            [function (v) { return Validation.RE.email.test(v.trim()); }, 'Enter a valid email address']
        ]},
        { id: 'password', checks: [
            [function (v) { return String(v).length >= pwMin; }, 'Password must be at least ' + pwMin + ' characters']
        ]}
    ]);

    var regValidate;
    window.__regValidate = regValidate = Validation.bindRules('register-form', [
        { id: 'driver-name', when: isDriver, checks: nameChecks },
        { id: 'driver-email', when: isDriver, checks: emailGmailChecks },
        { id: 'driver-phone', when: isDriver, checks: phoneChecks },
        { id: 'car-model', when: isDriver, checks: [
            [function (v) { return v.trim() !== ''; }, 'Car model is required'],
            [function (v) { return v.trim().length <= 100; }, 'Car model is too long']
        ]},
        { id: 'battery-capacity', when: isDriver, checks: [
            [function (v) { return v !== ''; }, 'Select your battery capacity']
        ]},
        { id: 'battery-other-input', when: function (getV) { return isDriver(getV) && getV('battery-capacity') === 'other'; }, checks: [
            [function (v) { var n = parseFloat(v); return v.trim() !== '' && !isNaN(n) && n > 0; }, 'Enter a valid capacity between 0.1 and 1000 kWh'],
            [function (v) { return parseFloat(v) <= 1000; }, 'Capacity looks too large (max 1000 kWh)']
        ]},
        { id: 'owner-name', when: isOwner, checks: nameChecks },
        { id: 'company-name', when: isOwner, checks: [
            [function (v) { return v.trim() !== ''; }, 'Company name is required'],
            [function (v) { return v.trim().length <= 150; }, 'Company name is too long']
        ]},
        { id: 'owner-email', when: isOwner, checks: emailGmailChecks },
        { id: 'owner-phone', when: isOwner, checks: phoneChecks },
        { id: 'bank-account', when: isOwner, checks: [
            [function (v) { return Validation.RE.bank.test(v.trim()); }, 'Bank account must be 5-20 digits']
        ]},
        { id: 'password', checks: [
            [function (v) { return v.length >= pwMin; }, 'Password must be at least ' + pwMin + ' characters']
        ]},
        { id: 'confirm-password', checks: [
            [function (v) { return v !== '' && v === document.getElementById('password').value; }, 'Passwords do not match']
        ]},
        { id: 'terms', checks: [
            [function (v) { return v === true; }, 'Please accept the Terms & Conditions']
        ]}
    ]);

    // ── intercept registration form ──
    if (registerForm) {
        registerForm.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!regValidate()) return; // inline errors shown; focus on first offender

            var formData = new FormData(registerForm);
            var data = {};
            formData.forEach(function (v, k) { data[k] = v; });

            // battery capacity "other" handling
            if (data.battery_capacity === 'other') {
                var otherInput = $('battery-other-input');
                if (otherInput && otherInput.value) {
                    data.battery_capacity = otherInput.value;
                } else {
                    if (typeof showToast === 'function') showToast('Please enter a custom battery capacity', 'error');
                    return;
                }
            }

            // client-side validation is handled by the declarative engine above;
            // reaching this point means every field passed its rules.

            // store for later
            pendingFormData = data;
            pendingEmail = data.email;
            pendingUserType = data.user_type || 'driver';

            // disable submit button
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Sending OTP...'; }

            // send OTP
            sendOtp(pendingEmail).then(function (result) {
                if (result.status === 'success') {
                    if (otpEmailDisplay) otpEmailDisplay.textContent = pendingEmail;
                    showOtpModal();
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account'; }
                } else {
                    if (typeof showToast === 'function') showToast(result.message || 'Failed to send OTP', 'error');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account'; }
                }
            }).catch(function () {
                if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account'; }
            });
        });
    }

    // ── OTP submit ──
    if (otpSubmitBtn) {
        otpSubmitBtn.addEventListener('click', function () {
            var otp = otpInput ? otpInput.value.trim() : '';
            if (!/^\d{6}$/.test(otp)) {
                if (otpError) otpError.textContent = 'Please enter a valid 6-digit code.';
                return;
            }

            otpSubmitBtn.disabled = true;
            otpSubmitBtn.textContent = 'Verifying...';
            if (otpError) otpError.textContent = '';

            verifyOtp(pendingEmail, otp).then(function (result) {
                if (result.status === 'success') {
                    // OTP verified — now complete registration
                    return completeRegistration(pendingFormData).then(function (regResult) {
                        if (regResult.status === 'success') {
                            hideOtpModal();
                            if (typeof showToast === 'function') {
                                showToast('Account created successfully! Redirecting...', 'success');
                            }
                            setTimeout(function () {
                                // Server sends the post-registration picture step; the
                                // login fallback covers any path where no redirect came.
                                window.location.href = regResult.redirect || ('login.php?type=' + pendingUserType);
                            }, 2000);
                        } else {
                            // Registration failed (e.g. duplicate email). The OTP is still
                            // valid on the server, so do NOT clear the input — the user can
                            // fix the form and retry. Show the backend's real message.
                            if (otpError) otpError.textContent = regResult.message || 'Registration failed.';
                            otpSubmitBtn.disabled = false;
                            otpSubmitBtn.textContent = 'Complete Registration';
                        }
                    });
                } else {
                    // Actual OTP problem (invalid/expired) — clear input and let them retry.
                    if (otpError) otpError.textContent = result.message || 'Invalid OTP.';
                    otpSubmitBtn.disabled = false;
                    otpSubmitBtn.textContent = 'Complete Registration';
                    if (otpInput) { otpInput.value = ''; otpInput.focus(); }
                }
            }).catch(function () {
                // Genuine network failure — keep the OTP value so a retry is one click.
                if (otpError) otpError.textContent = 'Network error. Please try again.';
                otpSubmitBtn.disabled = false;
                otpSubmitBtn.textContent = 'Complete Registration';
            });
        });
    }

    // ── resend OTP ──
    if (otpResendBtn) {
        otpResendBtn.addEventListener('click', function () {
            otpResendBtn.disabled = true;
            otpResendBtn.textContent = 'Resending...';
            if (otpError) otpError.textContent = '';

            sendOtp(pendingEmail).then(function (result) {
                if (result.status === 'success') {
                    if (typeof showToast === 'function') showToast('New OTP sent!', 'success');
                    if (otpInput) { otpInput.value = ''; otpInput.focus(); }
                } else {
                    if (otpError) otpError.textContent = result.message || 'Failed to resend OTP.';
                }
                otpResendBtn.disabled = false;
                otpResendBtn.textContent = 'Resend OTP';
            }).catch(function () {
                if (otpError) otpError.textContent = 'Network error.';
                otpResendBtn.disabled = false;
                otpResendBtn.textContent = 'Resend OTP';
            });
        });
    }

    // ── close modal on backdrop click ──
    if (otpModal) {
        otpModal.addEventListener('click', function (e) {
            if (e.target === otpModal) hideOtpModal();
        });
    }

})();