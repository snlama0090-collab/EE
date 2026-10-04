/**
 * validators.js — single client-side source of truth for auth field rules.
 *
 * MUST mirror the server rules (app/config/config.php + api/auth/*.php).
 * The password bounds arrive via window.PW_CONFIG, injected on every auth
 * page straight from the PHP constants, so the two layers cannot drift:
 *   <script>window.PW_CONFIG = {min: <?php echo PASSWORD_MIN_LENGTH; ?>, max: <?php echo PASSWORD_MAX_LENGTH; ?>};</script>
 * The numeric fallbacks below are a safety net for pages that omit the
 * injection — they match the current server policy.
 */
(function (window) {
    'use strict';

    var cfg = window.PW_CONFIG || {};
    var PW_MIN = (typeof cfg.min === 'number' && cfg.min > 0) ? cfg.min : 8;
    var PW_MAX = (typeof cfg.max === 'number' && cfg.max >= PW_MIN) ? cfg.max : 128;

    var RE = {
        email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        gmail: /^[a-zA-Z0-9._%+-]+@gmail\.com$/i,
        phone: /^(?:\+977\s?)?9[78]\d{8}$/
    };

    function trimmed(v) { return String(v == null ? '' : v).trim(); }

    window.AuthValidators = {
        PW_MIN: PW_MIN,
        PW_MAX: PW_MAX,
        RE: RE,
        // Plain email shape — login/forgot (server: validate_email()).
        isValidEmail: function (v) { return RE.email.test(trimmed(v)); },
        // Registration policy — valid email AND @gmail.com (server: validate_email()
        // + validate_gmail()).
        isValidGmail: function (v) { var s = trimmed(v); return RE.email.test(s) && RE.gmail.test(s); },
        // Nepali mobile (server: validate_phone()).
        isValidPhone: function (v) { return RE.phone.test(trimmed(v)); },
        // Length-only password policy (server: PASSWORD_MIN/MAX_LENGTH, mb_strlen).
        isPasswordValidLength: function (v) {
            var n = String(v == null ? '' : v).length;
            return n >= PW_MIN && n <= PW_MAX;
        }
    };
})(window);
