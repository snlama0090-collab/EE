<?php
/**
 * Reset-password endpoint — validates a password_reset token and sets a new password.
 *
 * POST /api/auth/reset-password.php  { "token": "...", "password": "..." }
 *
 * The role/table is derived from the token row itself (user_id vs owner_id) —
 * never from client input. Single-use: is_used=TRUE; the identity's
 * remember_tokens are wiped (kills stale auto-login). No auto-login: the user
 * is sent to login.php with the new password.
 */
header('Content-Type: application/json');
require_once '../../app/config/config.php';
require_once '../_rate_limit.php';
require_once '../../app/helpers/Auth.php';
require_once '../../app/helpers/Csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

Csrf::validate();

$input = json_decode(file_get_contents('php://input'), true);
$raw = trim($input['token'] ?? '');
$password = $input['password'] ?? '';
$fail400 = function ($msg) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
};

if ($raw === '' || strlen($password) < PASSWORD_MIN_LENGTH) {
    $fail400('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters');
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, user_id, owner_id FROM verification_tokens
                          WHERE token = ? AND token_type = 'password_reset'
                            AND is_used = FALSE AND expires_at > NOW()
                          LIMIT 1");
    $stmt->execute([hash('sha256', $raw)]);
    $row = $stmt->fetch();
    if (!$row) $fail400('This reset link is invalid or has expired. Please request a new one.');

    $db->beginTransaction();
    $newHash = hash_password($password);
    if ($row['user_id'] !== null) {
        $uid = (int) $row['user_id'];
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $uid]);
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = 'driver'")->execute([$uid]);
        $id_col = 'user_id';
    } else {
        $uid = (int) $row['owner_id'];
        $db->prepare("UPDATE owners SET password = ? WHERE id = ?")->execute([$newHash, $uid]);
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND user_type = 'owner'")->execute([$uid]);
        $id_col = 'owner_id';
    }
    $db->prepare("UPDATE verification_tokens SET is_used = TRUE WHERE id = ?")->execute([(int) $row['id']]);
    // Hygiene: purge any other unused reset tokens for this identity.
    $db->prepare("DELETE FROM verification_tokens WHERE $id_col = ? AND token_type = 'password_reset' AND is_used = FALSE")->execute([$uid]);
    $db->commit();

    echo json_encode(['status' => 'success', 'message' => 'Password updated. You can now sign in with your new password.', 'redirect' => 'login.php']);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Reset-password API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to reset the password right now.']);
}