<?php
header('Content-Type: application/json');
require_once '../../app/config/config.php';

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

if (strlen($password) < PASSWORD_MIN_LENGTH) {
    echo json_encode(['status' => 'error', 'message' => 'Password too short']);
    exit;
}

try {
    $db = getDB();
    $hashed_password = hash_password($password);
    
    if ($user_type === 'driver') {
        $car_model = sanitize($input['car_model'] ?? '');
        $battery = floatval($input['battery_capacity'] ?? 0);
        
        $stmt = $db->prepare("
            INSERT INTO users (email, password, name, phone, car_model, car_full_capacity_kwh)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$email, $hashed_password, $name, $phone, $car_model, $battery]);
        
    } elseif ($user_type === 'owner') {
        $company = sanitize($input['company_name'] ?? '');
        $bank = sanitize($input['bank_account'] ?? '');
        
        $stmt = $db->prepare("
            INSERT INTO owners (email, password, name, company_name, phone, bank_account_number)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$email, $hashed_password, $name, $company, $phone, $bank]);
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Registration successful'
    ]);
    
    log_message('INFO', "New $user_type registered: $email");
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo json_encode(['status' => 'error', 'message' => 'Email already registered']);
    } else {
        log_message('ERROR', "Registration error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    }
}
?>