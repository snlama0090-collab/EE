<?php
/**
 * Forgot-password endpoint — issues a single-use, 30-minute reset link.
 *
 * POST /api/auth/forgot-password.php  { "email": "...", "user_type": "driver"|"owner" }
 *
 * Anti-enumeration: the success response is IDENTICAL whether or not the email
 * exists — only a verified identity gets a token row and an email. Admins are
 * excluded (google.php:237-248 parity — ops resets admins manually).
 *
 * Cooldown: an unused password_reset token for the same identity created within
 * the last 60s is rejected (429) — prevents link/email flooding.
 *
 * Token storage: raw token goes ONLY into the emailed URL; the SHA-256 hash is
 * stored (Auth.php remember-token convention). Re-issue deletes prior unused
 * tokens for the identity, invalidating older links.
 */
header('Content-Type: application/json');
require_once '../../app/config/config.php';
require_once '../_rate_limit.php';
require_once '../../app/helpers/Auth.php';
require_once '../../app/helpers/Csrf.php';
require_once '../../app/helpers/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

Csrf::validate();

$input = json_decode(file_get_contents('php://input'), true);
$email = sanitize($input['email'] ?? '');
$user_type = sanitize($input['user_type'] ?? 'driver');

// Generic success — the same terminal response for every path (no enumeration).
$generic = function () {
    echo json_encode(['status' => 'success', 'message' => 'If that email is registered, a password reset link has been sent.']);
    exit;
};

if (!validate_email($email) || !in_array($user_type, ['driver', 'owner'], true)) $generic();

try {
    $db = getDB();
    $table = $user_type === 'driver' ? 'users' : 'owners';
    $id_col = $user_type === 'driver' ? 'user_id' : 'owner_id';
    $stmt = $db->prepare("SELECT id FROM $table WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $identity = $stmt->fetch();
    if (!$identity) $generic();
    $id_val = (int) $identity['id'];

    // Cooldown: an unused reset token issued <60s ago means we just emailed this link.
    $recent = $db->prepare("SELECT id FROM verification_tokens
                            WHERE $id_col = ? AND token_type = 'password_reset'
                              AND is_used = FALSE AND expires_at > NOW()
                              AND created_at > (NOW() - INTERVAL 60 SECOND)
                            LIMIT 1");
    $recent->execute([$id_val]);
    if ($recent->fetch()) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'A reset link was just sent. Please wait a minute before requesting another.']);
        exit;
    }

    // Reissue invalidates prior links: purge unused reset tokens for this identity.
    $db->prepare("DELETE FROM verification_tokens WHERE $id_col = ? AND token_type = 'password_reset' AND is_used = FALSE")
       ->execute([$id_val]);

    $raw = generate_token(32);
    $db->prepare("INSERT INTO verification_tokens ($id_col, token, token_type, expires_at)
                  VALUES (?, ?, 'password_reset', DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
       ->execute([$id_val, hash('sha256', $raw)]);

    $resetUrl = APP_URL . '/public/reset-password.php?token=' . $raw;
    if (!sendPasswordResetEmail($email, $resetUrl)) {
        // Mail transport down: keep the identical response (enumeration safety);
        // the user can re-request after the cooldown. Log loudly for ops.
        log_message('ERROR', "Password-reset email failed for $email ($user_type)");
    }
    $generic();
} catch (Throwable $e) {
    error_log('Forgot-password API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to process the request.']);
}