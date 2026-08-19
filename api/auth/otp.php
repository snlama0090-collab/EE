<?php
/**
 * OTP API — send & verify 6-digit OTPs for registration.
 *
 * POST /api/auth/otp.php
 *   action = 'send_otp'   →  { "email": "..." }
 *   action = 'verify_otp'  →  { "email": "...", "otp": "123456" }
 *
 * Notes:
 *  - send_otp rejects emails that are already registered (no OTP for you).
 *  - verify_otp does NOT delete the OTP row. It marks it verified=TRUE so
 *    a later registration failure (e.g. duplicate email race) doesn't burn
 *    the user's code. The row is deleted only after the INSERT succeeds.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$email  = trim($input['email'] ?? '');

// ── validate email ──
if (!validate_email($email) || !validate_gmail($email)) {
    echo json_encode(['status' => 'error', 'message' => 'Only @gmail.com addresses are allowed.']);
    exit;
}

try {
    $db = getDB();

    // ─────────────────────────────────────────────
    //  SEND OTP
    // ─────────────────────────────────────────────
    if ($action === 'send_otp') {
        // Don't email a code to someone who's already registered.
        $dup = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'This email is already registered. Try signing in instead.']);
            exit;
        }
        $dup = $db->prepare('SELECT 1 FROM owners WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'This email is already registered. Try signing in instead.']);
            exit;
        }

        // Purge any existing OTPs for this email (re-issuance deletes old records)
        $stmt = $db->prepare('DELETE FROM registration_otps WHERE email = ?');
        $stmt->execute([$email]);

        // Generate cryptographically secure 6-digit OTP
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Hash the OTP before storing
        $otpHash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);

        // 10-minute expiry
        $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

        $stmt = $db->prepare('INSERT INTO registration_otps (email, otp_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$email, $otpHash, $expiresAt]);

        // Send the email
        require_once __DIR__ . '/../../app/helpers/Mailer.php';
        $sent = sendOtpEmail($email, $otp);

        if ($sent) {
            echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email.']);
        } else {
            // Rollback: remove the OTP record since email failed
            $stmt = $db->prepare('DELETE FROM registration_otps WHERE email = ?');
            $stmt->execute([$email]);
            echo json_encode(['status' => 'error', 'message' => 'Failed to send OTP email. Please try again.']);
        }
        exit;
    }

    // ─────────────────────────────────────────────
    //  VERIFY OTP
    // ─────────────────────────────────────────────
    if ($action === 'verify_otp') {
        $otp = trim($input['otp'] ?? '');

        if (!preg_match('/^\d{6}$/', $otp)) {
            echo json_encode(['status' => 'error', 'message' => 'OTP must be a 6-digit code.']);
            exit;
        }

        // Fetch the latest active OTP record for this email
        $stmt = $db->prepare('SELECT * FROM registration_otps WHERE email = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        $record = $stmt->fetch();

        if (!$record) {
            echo json_encode(['status' => 'error', 'message' => 'No OTP found. Request a new one.']);
            exit;
        }

        // Check expiry
        if (strtotime($record['expires_at']) <= time()) {
            // Delete expired record
            $db->prepare('DELETE FROM registration_otps WHERE id = ?')->execute([$record['id']]);
            echo json_encode(['status' => 'error', 'message' => 'OTP has expired. Request a new one.']);
            exit;
        }

        // Check attempt limit
        if ((int) $record['attempts'] >= OTP_MAX_ATTEMPTS) {
            // Exhausted — delete so they must request a fresh one
            $db->prepare('DELETE FROM registration_otps WHERE id = ?')->execute([$record['id']]);
            echo json_encode(['status' => 'error', 'message' => 'Too many failed attempts. Request a new OTP.']);
            exit;
        }

        // Verify the OTP against the stored hash
        if (!password_verify($otp, $record['otp_hash'])) {
            // Increment attempts
            $stmt = $db->prepare('UPDATE registration_otps SET attempts = attempts + 1 WHERE id = ?');
            $stmt->execute([$record['id']]);
            $remaining = OTP_MAX_ATTEMPTS - ((int) $record['attempts'] + 1);
            echo json_encode(['status' => 'error', 'message' => "Invalid OTP. {$remaining} attempt(s) remaining."]);
            exit;
        }

        // Mark verified — DO NOT delete: the row is still needed by
        // register.php, which deletes it only after the user INSERT succeeds.
        $db->prepare('UPDATE registration_otps SET verified = TRUE WHERE id = ?')->execute([$record['id']]);

        echo json_encode(['status' => 'success', 'message' => 'OTP verified successfully.']);
        exit;
    }

    // ── unknown action ──
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);

} catch (Throwable $e) {
    // Never let a raw PHP fatal/PDO error leak to the client as non-JSON.
    error_log('OTP API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to process OTP request. Please try again.']);
}