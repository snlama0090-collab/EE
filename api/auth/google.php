<?php
header('Content-Type: application/json');
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';
require_once '../../app/helpers/Csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Login-CSRF guard: guest token (provisional sign-in) or session token (complete_profile).
Csrf::validate();

// Dual-mode input: multipart when a signup/completion picture is attached,
// JSON for every existing caller. Same split as api/auth/register.php.
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
    $input = $_POST;
    $pfp_file = $_FILES['pfp'] ?? null;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $pfp_file = null;
}

// ── Completion step of Google sign-up: session-bound, NOT token-bound ──
// Runs under the session startSession() minted during provisional creation,
// guarded by CSRF like every other state-changing endpoint.
if (($input['action'] ?? '') === 'complete_profile') {
    if (!Auth::isSessionValid()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please sign in again.']);
        exit;
    }
    $auth_type = Auth::getCurrentUserType();
    if (!in_array($auth_type, ['driver', 'owner'], true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Profile completion is for driver and owner accounts only']);
        exit;
    }

    // Matrix row 6 parity: finished profiles don't rewrite through this endpoint
    // (name/vehicle/company edits belong to a future settings surface, not here).
    if (!isset($_SESSION['profile_complete']) || $_SESSION['profile_complete'] !== false) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Profile already completed']);
        exit;
    }

    $db = getDB();
    $fail = function ($msg, $code = 400) {
        http_response_code($code);
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    };

    $name = sanitize($input['name'] ?? '');
    if (mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 100) $fail('Name must be between 2 and 100 characters');
    if (preg_match_all('/[A-Za-z\\x{00C0}-\\x{024F}]/u', $name) < 2) $fail('Please enter your real name');

    if ($auth_type === 'driver') {
        $pfpDir = PUBLIC_PATH . "/assets/uploads/pfp";
        $targetPfp = $pfpDir . '/' . Auth::getCurrentUserId() . '.jpg';
        $car_model = sanitize($input['car_model'] ?? '');
        $battery   = floatval($input['battery_capacity'] ?? 0);
        if ($car_model === '') $fail('Car model is required');
        if (mb_strlen($car_model) > 100) $fail('Car model is too long');
        if ($battery <= 0) $fail('Battery capacity must be a positive number');
    } else {
        $pfpDir = PUBLIC_PATH . "/assets/uploads/pfp";
        $targetPfp = $pfpDir . '/owner_' . Auth::getCurrentUserId() . '.jpg';
        $company = sanitize($input['company_name'] ?? '');
        $bank    = sanitize($input['bank_account'] ?? '');
        if ($company === '') $fail('Company name is required');
        if (mb_strlen($company) > 150) $fail('Company name is too long');
        if (!preg_match('/^[0-9]{5,20}$/', $bank)) $fail('Bank account must be 5-20 digits');
    }

    // Optional completion-time picture: validated and WRITTEN BEFORE the profile
    // UPDATEs, so a failed image genuinely leaves the provisional account
    // untouched (fail-early, matching register.php's rollback discipline). If the
    // image lands but the UPDATE then errors (rare), the retry overwrites the
    // orphaned image file - no half-completed state either way.
    if ($pfp_file !== null && $pfp_file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($pfp_file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || @getimagesize($pfp_file['tmp_name']) === false || $pfp_file['size'] > MAX_UPLOAD_SIZE) {
            $fail('Invalid image. Only JPG, PNG or GIF images under 5MB are allowed.');
        }
        if (!is_dir($pfpDir) && !mkdir($pfpDir, 0755, true)) {
            log_message('ERROR', "Completion pfp: could not create dir $pfpDir");
            $fail('Could not save the profile picture. Please try again.');
        }
        if (!resize_profile_image($pfp_file['tmp_name'], $targetPfp)) {
            $fail('Could not process the image. Please try again.');
        }
    }

    if ($auth_type === 'driver') {
        $db->prepare("UPDATE users SET name = ?, car_model = ?, car_full_capacity_kwh = ?, profile_complete = TRUE WHERE id = ?")
           ->execute([$name, $car_model, $battery, Auth::getCurrentUserId()]);
    } else {
        $db->prepare("UPDATE owners SET name = ?, company_name = ?, bank_account_number = ?, profile_complete = TRUE WHERE id = ?")
           ->execute([$name, $company, $bank, Auth::getCurrentUserId()]);
    }

    // Preset selection (optional; an uploaded picture wins if both are present).
    // Read from $input so both the multipart ($_POST) and JSON paths carry it.
    $preset = sanitize($input['preset'] ?? '');
    if ($preset !== '' && !($pfp_file !== null && $pfp_file['error'] === UPLOAD_ERR_OK)) {
        if (apply_preset($preset, $targetPfp)) {
            // preset copied over the picture slot
        } else {
            log_message('WARNING', "Completion pfp: preset '$preset' unavailable - skipped for user " . Auth::getCurrentUserId());
        }
    }

    $_SESSION['profile_complete'] = true;
    echo json_encode([
        'status'   => 'success',
        'message'  => 'Profile completed',
        'redirect' => APP_URL . '/public/profile-picture.php'
    ]);
    exit;
}

$id_token = $input['token'] ?? '';
$user_type = sanitize($input['user_type'] ?? 'driver');

if (empty($id_token)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID Token']);
    exit;
}

try {
    // 1. Verify token with Google API
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($id_token);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to verify token with Google']);
        exit;
    }
    
    $payload = json_decode($response, true);
    if (!$payload) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse Google payload']);
        exit;
    }
    
    // Verify token audience matches our registered client ID
    if ($payload['aud'] !== GOOGLE_CLIENT_ID) {
        echo json_encode(['status' => 'error', 'message' => 'OAuth client ID mismatch']);
        exit;
    }
    
    $email = sanitize($payload['email'] ?? '');
    $name = sanitize($payload['name'] ?? '');
    $picture = sanitize($payload['picture'] ?? '');
    
    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Could not retrieve email from Google']);
        exit;
    }
    
    $db = getDB();
    $user_id = null;
    
    // 2. Check if user already exists
    $profile_complete = true;
    
    if ($user_type === 'driver') {
        $stmt = $db->prepare("SELECT id, name, profile_pic, profile_complete FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user_id = $user['id'];
            // Existing row - includes implicit same-email LINKING of password accounts.
            // SAFE BY CONSTRUCTION (decision D): Google has verified the caller owns
            // this exact mailbox, so a local account on that mailbox is reachable only
            // by its legitimate owner. No impersonation surface; stored password hash
            // below is never overwritten by the random one used for provisionals.
            $profile_complete = (bool)$user['profile_complete'];
        } else {
            // Provisional registration: real verified identity ONLY - no fabricated
            // car fields. Role-specific data arrives via complete-profile.php.
            // ponytail: legacy flow inserted car_model='Generic EV', 50.00 kWh here;
            // retroactive cleanup of those rows is tracked in PROJECT_REPORT §17.
            $random_pass = hash_password(bin2hex(random_bytes(16)));
            
            $stmt = $db->prepare("
                INSERT INTO users (email, password, name, profile_pic, email_verified, profile_complete)
                VALUES (?, ?, ?, ?, TRUE, FALSE)
            ");
            $stmt->execute([$email, $random_pass, $name, $picture]);
            $user_id = $db->lastInsertId();
            $profile_complete = false;
            
            log_message('INFO', "New driver auto-registered via Google: $email");
        }
        
    } elseif ($user_type === 'owner') {
        $stmt = $db->prepare("SELECT id, company_name as name, profile_complete FROM owners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user_id = $user['id'];
            // Same verified-email safety argument as the driver branch (decision D).
            $profile_complete = (bool)$user['profile_complete'];
        } else {
            // Provisional registration: NO fabricated company naming either -
            // NOT NULL satisfied with '' until completion fills the real name.
            // approval_status='approved' mirrors legacy intent: it gates the operator
            // account, not stations (station submissions run their own pipeline).
            $random_pass = hash_password(bin2hex(random_bytes(16)));
            
            $stmt = $db->prepare("
                INSERT INTO owners (email, password, name, company_name, email_verified, approval_status, profile_complete)
                VALUES (?, ?, ?, '', TRUE, 'approved', FALSE)
            ");
            $stmt->execute([$email, $random_pass, $name]);
            $user_id = $db->lastInsertId();
            $profile_complete = false;
            
            log_message('INFO', "New owner auto-registered via Google: $email");
        }
        
    } elseif ($user_type === 'admin') {
        // Admins cannot auto-register via Google for safety. They must already exist.
        $stmt = $db->prepare("SELECT id, name FROM admins WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user_id = $user['id'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active admin account found for this Google email.']);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user type']);
        exit;
    }
    
    // 3. Establish session - the gate flag travels with the identity
    Auth::startSession($user_id, $user_type, false, $profile_complete);
    
    // Log activity
    $log_user_id   = ($user_type === 'driver') ? $user_id : null;
    $log_owner_id  = ($user_type === 'owner')  ? $user_id : null;
    $log_admin_id  = ($user_type === 'admin')  ? $user_id : null;
    $log_stmt = $db->prepare("INSERT INTO activity_logs (admin_id, user_id, owner_id, action, resource_type, details) VALUES (?, ?, ?, 'google_login', 'auth', ?)");
    $log_stmt->execute([$log_admin_id, $log_user_id, $log_owner_id, json_encode(['email' => $email, 'user_type' => $user_type])]);

    // Provisional accounts route to profile completion instead of a dashboard.
    // Role-agnostic status keeps both frontend handlers' redirect paths identical.
    if (!$profile_complete) {
        echo json_encode([
            'status'   => 'incomplete',
            'message'  => 'Finish setting up your profile',
            'redirect' => 'complete-profile.php'
        ]);
        exit;
    }

    // Return redirect URL
    $redirectMap = [
        'driver' => 'dashboard/driver.php',
        'owner'  => 'dashboard/owner.php',
        'admin'  => 'dashboard/admin.php'
    ];
    $redirect = $redirectMap[$user_type];
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Google Login Successful',
        'redirect' => $redirect
    ]);
    
} catch (Exception $e) {
    log_message('ERROR', "Google Auth endpoint error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error occurred during Google verification.']);
}
?>
