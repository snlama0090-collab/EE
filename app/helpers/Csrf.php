<?php
/**
 * CSRF Helper
 * Session-bound token guarding every authenticated state-changing endpoint.
 * Minted in Auth::startSession(), delivered via <meta name="csrf-token"> in the
 * dashboard shells, returned by browsers in the X-CSRF-Token header
 * (see public/assets/js/csrf.js for the automatic injection wrapper).
 */

require_once __DIR__ . '/../config/config.php';

class Csrf {

    /**
     * Current session token; lazily minted if the session predates it.
     */
    public static function token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = generate_token(32);
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Hard gate for non-GET requests. Timing-safe comparison against the
     * X-CSRF-Token header; exits 403 with a distinct, actionable message.
     */
    public static function validate() {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(self::token(), $sent)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid security token. Please refresh the page and try again.'
            ]);
            exit;
        }
    }
}

?>