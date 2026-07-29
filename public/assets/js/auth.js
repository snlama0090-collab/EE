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

    // ── send OTP ──
    function sendOtp(email) {
        return fetch('/EE/api/auth/otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_otp', email: email })
        }).then(function (r) { return r.json(); });
    }

    // ── verify OTP ──
    function verifyOtp(email, otp) {
        return fetch('/EE/api/auth/otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify_otp', email: email, otp: otp })
        }).then(function (r) { return r.json(); });
    }

    // ── final registration call ──
    function completeRegistration(data) {
        return fetch('/EE/api/auth/register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    // ── intercept registration form ──
    if (registerForm) {
        registerForm.addEventListener('submit', function (e) {
            e.preventDefault();

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

            // client-side validation
            if (data.password !== data.confirm_password) {
                if (typeof showToast === 'function') showToast('Passwords do not match', 'error');
                return;
            }
            if (data.password.length < 8) {
                if (typeof showToast === 'function') showToast('Password must be at least 8 characters', 'error');
                return;
            }
            if (!data.terms) {
                if (typeof showToast === 'function') showToast('Please accept terms & conditions', 'error');
                return;
            }

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
                                window.location.href = 'login.php?type=' + pendingUserType;
                            }, 2000);
                        } else {
                            if (otpError) otpError.textContent = regResult.message || 'Registration failed.';
                            otpSubmitBtn.disabled = false;
                            otpSubmitBtn.textContent = 'Complete Registration';
                        }
                    });
                } else {
                    if (otpError) otpError.textContent = result.message || 'Invalid OTP.';
                    otpSubmitBtn.disabled = false;
                    otpSubmitBtn.textContent = 'Complete Registration';
                    if (otpInput) { otpInput.value = ''; otpInput.focus(); }
                }
            }).catch(function () {
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