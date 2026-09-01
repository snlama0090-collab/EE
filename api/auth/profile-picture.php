<?php
// Post-registration profile-picture step (both signup flows land here after
// account creation). Writes to an EXISTING account's picture slot - no signup
// transaction to wrap; a failed image simply leaves the previous state in place.
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/Auth.php';
require_once __DIR__ . '/../../app/helpers/Csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

Csrf::validate();

if (!Auth::isSessionValid() || !in_array(Auth::getCurrentUserType(), ['driver', 'owner'], true)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please sign in again.']);
    exit;
}

$role = Auth::getCurrentUserType();
// Same fixed-name convention as signup: driver {id}.jpg, owner owner_{id}.jpg.
$pfpName = ($role === 'owner') ? 'owner_' . Auth::getCurrentUserId() . '.jpg' : Auth::getCurrentUserId() . '.jpg';
$targetPath = PUBLIC_PATH . '/assets/uploads/pfp/' . $pfpName;

// Dual-mode input: multipart when a file is attached, JSON for preset-only.
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
    $input = $_POST;
    $pfp_file = $_FILES['pfp'] ?? null;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $pfp_file = null;
}

$fail = function ($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
};

$pfpDir = PUBLIC_PATH . '/assets/uploads/pfp';
$preset = sanitize($input['preset'] ?? '');

if ($pfp_file !== null && $pfp_file['error'] === UPLOAD_ERR_OK) {
    // Same validation trio as both signup APIs.
    $ext = strtolower(pathinfo($pfp_file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) || @getimagesize($pfp_file['tmp_name']) === false || $pfp_file['size'] > MAX_UPLOAD_SIZE) {
        $fail('Invalid image. Only JPG, PNG or GIF images under 5MB are allowed.');
    }
    if (!is_dir($pfpDir) && !mkdir($pfpDir, 0755, true)) {
        log_message('ERROR', "Profile picture: could not create dir $pfpDir");
        $fail('Could not save the profile picture. Please try again.');
    }
    if (!resize_profile_image($pfp_file['tmp_name'], $targetPath)) {
        $fail('Could not process the image. Please try again.');
    }
} elseif ($preset !== '') {
    // Uploaded file wins if both are present (same precedence as signup APIs).
    // Hard-fail (not the signup APIs' graceful skip): here the picture IS the
    // request's purpose, and the page's Skip button remains the escape hatch.
    if (!apply_preset($preset, $targetPath)) {
        log_message('WARNING', "Profile picture: preset '$preset' unavailable for user " . Auth::getCurrentUserId());
        $fail('Selected preset is unavailable. Pick another one or upload an image.');
    }
} else {
    $fail('Nothing to save — pick a preset or upload an image first.');
}

echo json_encode([
    'status' => 'success',
    'message' => 'Profile picture saved',
    'redirect' => APP_URL . '/public/dashboard/' . $role . '.php'
]);
