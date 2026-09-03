<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/helpers/Csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Login-CSRF guard: token minted by public/register.php's guest session.
Csrf::validate();

// Dual-mode input: browsers uploading a signup picture send multipart FormData;
// every existing caller (auth.js without a file, the suite) sends JSON — the
// JSON path is byte-for-byte the historical one.
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
    $input = $_POST;
    $pfp_file = $_FILES['pfp'] ?? null;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $pfp_file = null;
}

$user_type = $input['user_type'] ?? '';
$email = sanitize($input['email'] ?? '');
$password = $input['password'] ?? '';
$name = sanitize($input['name'] ?? '');
$phone = sanitize($input['phone'] ?? '');

// Validate input
if (!validate_email($email)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit;
}

if (!validate_gmail($email)) {
    echo json_encode(['status' => 'error', 'message' => 'Only @gmail.com addresses are allowed.']);
    exit;
}

if (strlen($password) < PASSWORD_MIN_LENGTH) {
    echo json_encode(['status' => 'error', 'message' => 'Password too short']);
    exit;
}

if (strlen($name) < NAME_MIN_LENGTH || strlen($name) > NAME_MAX_LENGTH) {
    echo json_encode(['status' => 'error', 'message' => 'Name must be between ' . NAME_MIN_LENGTH . ' and ' . NAME_MAX_LENGTH . ' characters']);
    exit;
}

// ── Role-field hardening: DB-free checks, validated BEFORE the OTP gate ──
if ($user_type === 'driver') {
    $car_model = sanitize($input['car_model'] ?? '');
    $battery = floatval($input['battery_capacity'] ?? 0);

    if ($car_model === '') {
        echo json_encode(['status' => 'error', 'message' => 'Car model is required']);
        exit;
    }
    if ($battery <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Battery capacity must be a positive number']);
        exit;
    }
} elseif ($user_type === 'owner') {
    $company = sanitize($input['company_name'] ?? '');
    $bank = sanitize($input['bank_account'] ?? '');

    if ($company === '') {
        echo json_encode(['status' => 'error', 'message' => 'Company name is required']);
        exit;
    }
    if (!preg_match('/^[0-9]{5,20}$/', $bank)) {
        echo json_encode(['status' => 'error', 'message' => 'Bank account must be 5-20 digits']);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user type']);
    exit;
}

if (!validate_phone($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid phone number. Expected format: +977 98XXXXXXXX or 98XXXXXXXX']);
    exit;
}

try {
    $db = getDB();
    $hashed_password = hash_password($password);

    // ── OTP gate: registration is only allowed for a verified OTP row ──
    $stmt = $db->prepare(
        'SELECT id FROM registration_otps
         WHERE email = ? AND verified = TRUE AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $otp_record = $stmt->fetch();

    if (!$otp_record) {
        echo json_encode(['status' => 'error', 'message' => 'Email not verified. Please complete OTP verification first.']);
        exit;
    }

    // ── Duplicate email guard before starting the transaction ──
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

    $db->beginTransaction();

    if ($user_type === 'driver') {
        $stmt = $db->prepare("
            INSERT INTO users (email, password, name, phone, car_model, car_full_capacity_kwh)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$email, $hashed_password, $name, $phone, $car_model, $battery]);

    } elseif ($user_type === 'owner') {
        $stmt = $db->prepare("
            INSERT INTO owners (email, password, name, company_name, phone, bank_account_number)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([$email, $hashed_password, $name, $company, $phone, $bank]);
    }

    $new_id = (int) $db->lastInsertId();

    // Optional signup-time profile picture (driver avatar / owner logo).
    // Written inside the transaction: any failure rolls the whole account back
    // so no orphan user or half-registered state exists. Unlike the dashboard
    // (raw-move fallback), a brand-new account simply aborts - the user can
    // retry registration with a valid image.
    if ($pfp_file !== null && $pfp_file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($pfp_file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || @getimagesize($pfp_file['tmp_name']) === false || $pfp_file['size'] > MAX_UPLOAD_SIZE) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'error_code' => 'invalid_image', 'message' => 'Invalid image. Only JPG, PNG or GIF images under 5MB are allowed.']);
            exit;
        }
        $pfpDir = PUBLIC_PATH . "/assets/uploads/pfp";
        if (!is_dir($pfpDir) && !mkdir($pfpDir, 0755, true)) {
            $db->rollBack();
            log_message('ERROR', "Signup pfp: could not create dir $pfpDir");
            echo json_encode(['status' => 'error', 'error_code' => 'upload_failed', 'message' => 'Could not save the profile picture. Registration aborted.']);
            exit;
        }
        $pfpName = ($user_type === 'owner') ? "owner_{$new_id}.jpg" : "{$new_id}.jpg";
        if (!resize_profile_image($pfp_file['tmp_name'], $pfpDir . '/' . $pfpName)) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'error_code' => 'upload_failed', 'message' => 'Could not process the image. Registration aborted.']);
            exit;
        }
    }

    // Preset selection (optional; an uploaded picture wins if both are present).
    // Read from $input so both the multipart ($_POST) and JSON paths carry it.
    $preset = sanitize($input['preset'] ?? '');
    if ($preset !== '' && !($pfp_file !== null && $pfp_file['error'] === UPLOAD_ERR_OK)) {
        $pfpName = ($user_type === 'owner') ? "owner_{$new_id}.jpg" : "{$new_id}.jpg";
        if (apply_preset($preset, PUBLIC_PATH . "/assets/uploads/pfp/" . $pfpName)) {
            // preset copied over the picture slot
        } else {
            log_message('WARNING', "Signup pfp: preset '$preset' unavailable - skipped for user {$new_id}");
        }
    }

    // INSERT succeeded — only now consume the verified OTP row.
    $db->prepare('DELETE FROM registration_otps WHERE email = ?')->execute([$email]);

    $db->commit();

    // 2026-09-01 REVERT (owner decision): registration does NOT establish a
    // session - auto-login shipped without explicit authorization (a task-
    // resumption prompt was treated as approval) and is rolled back. The client
    // is sent to login.php with a forward param so the post-registration picture
    // step still runs - but only after a real password-verified login.
    echo json_encode([
        'status' => 'success',
        'message' => 'Registration successful',
        'redirect' => APP_URL . '/public/login.php?type=' . urlencode($user_type) . '&next=profile-picture.php'
    ]);

    log_message('INFO', "New $user_type registered: $email");

} catch (PDOException $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo json_encode(['status' => 'error', 'message' => 'Email already registered. Try signing in instead.']);
    } else {
        log_message('ERROR', "Registration error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    }
}