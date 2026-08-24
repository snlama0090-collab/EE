<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

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

// Complexity rules honored from config (previously dead flags — enforced since 2026-08-24)
if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
    echo json_encode(['status' => 'error', 'message' => 'Password must contain at least one uppercase letter']);
    exit;
}

if (PASSWORD_REQUIRE_NUMBERS && !preg_match('/[0-9]/', $password)) {
    echo json_encode(['status' => 'error', 'message' => 'Password must contain at least one number']);
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

    // INSERT succeeded — only now consume the verified OTP row.
    $db->prepare('DELETE FROM registration_otps WHERE email = ?')->execute([$email]);

    $db->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Registration successful'
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