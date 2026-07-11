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

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$email = sanitize($input['email'] ?? '');
$password = $input['password'] ?? '';
$user_type = sanitize($input['user_type'] ?? 'driver');
$remember = $input['remember'] ?? false;

// Validate input
if (!validate_email($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}

try {
    $db = getDB();
    
    if ($user_type === 'driver') {
        $stmt = $db->prepare("SELECT id, password, name FROM users WHERE email = ? AND status = 'active'");
    } elseif ($user_type === 'owner') {
        $stmt = $db->prepare("SELECT id, password, company_name as name FROM owners WHERE email = ? AND status = 'active'");
    } elseif ($user_type === 'admin') {
        $stmt = $db->prepare("SELECT id, password, name FROM admins WHERE email = ? AND status = 'active'");
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user type']);
        exit;
    }
    
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !verify_password($password, $user['password'])) {
        log_message('WARNING', "Failed login attempt for $email ($user_type)");
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
        exit;
    }
    
    // Start session
    Auth::startSession($user['id'], $user_type, $remember);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'data' => [
            'user_id' => $user['id'],
            'name' => $user['name'],
            'type' => $user_type
        ]
    ]);
    
    log_message('INFO', "User {$user['id']} ($user_type) logged in");
    
} catch (Exception $e) {
    log_message('ERROR', "Login error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
?>