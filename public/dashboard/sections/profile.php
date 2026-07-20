<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Handle profile updates via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $car_model = sanitize($_POST['car_model'] ?? '');
    $car_full_capacity_kwh = floatval($_POST['car_full_capacity_kwh'] ?? 50.0);
    $charger_preference = sanitize($_POST['charger_preference'] ?? 'ac_22kw');
    
    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => 'Name cannot be empty.']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            UPDATE users 
            SET name = ?, phone = ?, car_model = ?, car_full_capacity_kwh = ?, charger_preference = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $phone, $car_model, $car_full_capacity_kwh, $charger_preference, $user_id]);
        
        // Handle Avatar File Upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && in_array($file['type'], ['image/jpeg', 'image/png', 'image/gif'])) {
                if ($file['size'] <= 5 * 1024 * 1024) {
                    $pfpDir = PUBLIC_PATH . "/assets/uploads/pfp";
                    if (!is_dir($pfpDir)) {
                        mkdir($pfpDir, 0755, true);
                    }
                    $targetPath = $pfpDir . "/{$user_id}.jpg";
                    
                    move_uploaded_file($file['tmp_name'], $targetPath);
                }
            }
        }
        
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        log_message('ERROR', "Profile update failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to save changes.']);
    }
    exit;
}

// Fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Profile picture path
$profilePicPath = '../assets/img/default-avatar.svg';
$profilePicAbsolute = PUBLIC_PATH . "/assets/uploads/pfp/{$user_id}.jpg";
if (file_exists($profilePicAbsolute)) {
    $profilePicPath = "../assets/uploads/pfp/{$user_id}.jpg";
}
?>

<div class="profile-container">
    <h2><i class="fas fa-user" style="margin-right:8px;color:var(--muted-foreground);"></i> Profile Settings</h2>
    <p style="color:var(--gray); margin-bottom:24px;">Manage your driver information, preferences, and electric vehicle configurations.</p>

    <div class="dashboard-section-card">
        <form id="driver-profile-form" method="POST" onsubmit="saveProfile(event)" enctype="multipart/form-data">
            
            <!-- Avatar Section -->
            <div style="display:flex; align-items:center; gap:20px; margin-bottom:24px; border-bottom:1px solid var(--border); padding-bottom:20px;">
                <div style="position:relative; display:inline-block;">
                    <img src="<?php echo htmlspecialchars($profilePicPath); ?>?t=<?php echo time(); ?>" alt="Avatar" style="width:90px; height:90px; border-radius:50%; object-fit:cover; border:3px solid var(--primary);">
                    <label for="avatar-input" style="position:absolute; bottom:0; right:0; background:var(--primary); color:white; border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,0.2);">
                        <i class="fas fa-camera" style="font-size:14px;"></i>
                    </label>
                    <input type="file" id="avatar-input" name="avatar" accept="image/*" style="display:none;">
                </div>
                <div>
                    <h4 style="margin-bottom:6px;">Profile Picture</h4>
                    <p style="font-size:11px; color:var(--gray); margin-top:4px;">Supports PNG, JPG, or GIF up to 5MB.</p>
                </div>
            </div>

            <!-- Profile Info Fields -->
            <h3 style="margin-bottom:16px;">Driver Details</h3>
            <div class="form-grid">
                <div class="form-control-group">
                    <label for="name">Full Name *</label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($user['name']); ?>">
                </div>

                <div class="form-control-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+977 98XXXXXXXX">
                </div>

                <div class="form-control-group">
                    <label for="email">Email Address (Read-only)</label>
                    <input type="email" id="email" readonly value="<?php echo htmlspecialchars($user['email']); ?>" style="background-color: var(--light); color: var(--gray); cursor: not-allowed;">
                </div>
            </div>

            <!-- Vehicle Settings -->
            <h3 style="margin:24px 0 16px 0;">Electric Vehicle Information</h3>
            <div class="form-grid">
                <div class="form-control-group">
                    <label for="car_model">EV Model</label>
                    <input type="text" id="car_model" name="car_model" value="<?php echo htmlspecialchars($user['car_model'] ?? ''); ?>" placeholder="e.g. Tesla Model Y">
                </div>

                <div class="form-control-group">
                    <label for="car_full_capacity_kwh">Battery Full Capacity (kWh)</label>
                    <input type="number" step="0.1" id="car_full_capacity_kwh" name="car_full_capacity_kwh" value="<?php echo htmlspecialchars($user['car_full_capacity_kwh'] ?? '50.0'); ?>" placeholder="Capacity in kWh">
                </div>

                <div class="form-control-group">
                    <label for="charger_preference">Preferred Charger Connector</label>
                    <select id="charger_preference" name="charger_preference" class="sort-select" style="margin:0; width:100%;">
                        <option value="any" <?php echo ($user['charger_preference'] === 'any') ? 'selected' : ''; ?>>Any / No Preference</option>
                        <option value="dc_fast" <?php echo ($user['charger_preference'] === 'dc_fast') ? 'selected' : ''; ?>>DC Fast (CCS2)</option>
                        <option value="ac_22kw" <?php echo ($user['charger_preference'] === 'ac_22kw') ? 'selected' : ''; ?>>AC 22kW Type 2</option>
                        <option value="ac_11kw" <?php echo ($user['charger_preference'] === 'ac_11kw') ? 'selected' : ''; ?>>AC 11kW Type 2</option>
                        <option value="ac_7kw" <?php echo ($user['charger_preference'] === 'ac_7kw') ? 'selected' : ''; ?>>AC 7kW Type 2</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:24px;">
                <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #007AFF 0%, #0051D5 100%); box-shadow: 0 4px 12px rgba(0, 122, 255, 0.3);">
                    <i class="fas fa-save"></i> Save Driver Settings
                </button>
            </div>
        </form>
    </div>
</div>

