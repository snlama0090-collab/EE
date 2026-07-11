<?php
header('Content-Type: application/json');
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // ponytail: set false for local-only dev
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
    if ($user_type === 'driver') {
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user_id = $user['id'];
        } else {
            // Auto-register new driver
            $random_pass = hash_password(bin2hex(random_bytes(16)));
            
            $stmt = $db->prepare("
                INSERT INTO users (email, password, name, profile_pic, car_model, car_full_capacity_kwh, email_verified)
                VALUES (?, ?, ?, ?, 'Generic EV', 50.00, TRUE)
            ");
            $stmt->execute([$email, $random_pass, $name, $picture]);
            $user_id = $db->lastInsertId();
            
            log_message('INFO', "New driver auto-registered via Google: $email");
        }
        
    } elseif ($user_type === 'owner') {
        $stmt = $db->prepare("SELECT id, company_name as name FROM owners WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user_id = $user['id'];
        } else {
            // Auto-register new owner
            $random_pass = hash_password(bin2hex(random_bytes(16)));
            $company_name = $name . ' Enterprise';
            
            $stmt = $db->prepare("
                INSERT INTO owners (email, password, name, company_name, email_verified, approval_status)
                VALUES (?, ?, ?, ?, TRUE, 'approved')
            ");
            $stmt->execute([$email, $random_pass, $name, $company_name]);
            $user_id = $db->lastInsertId();
            
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
    
    // 3. Establish session
    Auth::startSession($user_id, $user_type, false);
    
    // Log activity
    $log_user_id   = ($user_type === 'driver') ? $user_id : null;
    $log_owner_id  = ($user_type === 'owner')  ? $user_id : null;
    $log_admin_id  = ($user_type === 'admin')  ? $user_id : null;
    $log_stmt = $db->prepare("INSERT INTO activity_logs (admin_id, user_id, owner_id, action, resource_type, details) VALUES (?, ?, ?, 'google_login', 'auth', ?)");
    $log_stmt->execute([$log_admin_id, $log_user_id, $log_owner_id, json_encode(['email' => $email, 'user_type' => $user_type])]);

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
