<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $description = sanitize($_POST['description'] ?? '');
    $company_name = sanitize($_POST['company_name'] ?? '');
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $bank_account_number = sanitize($_POST['bank_account_number'] ?? '');
    $bank_name = sanitize($_POST['bank_name'] ?? '');
    $account_holder_name = sanitize($_POST['account_holder_name'] ?? '');
    
    if (empty($company_name) || empty($name) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Company Name, Contact Person, and Phone are required.']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE owners 
            SET description = ?, company_name = ?, name = ?, phone = ?, bank_account_number = ?, bank_name = ?, account_holder_name = ?
            WHERE id = ?
        ");
        $stmt->execute([$description, $company_name, $name, $phone, $bank_account_number, $bank_name, $account_holder_name, $user_id]);
        
        // Handle Logo File Upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif']) && in_array($file['type'], ['image/jpeg', 'image/png', 'image/gif'])) {
                if ($file['size'] <= 5 * 1024 * 1024) {
                    $logoDir = PUBLIC_PATH . "/assets/uploads/pfp";
                    if (!is_dir($logoDir)) {
                        mkdir($logoDir, 0755, true);
                    }
                    $targetPath = $logoDir . "/owner_{$user_id}.jpg";
                    move_uploaded_file($file['tmp_name'], $targetPath);
                }
            }
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Company Profile updated successfully.']);
    } catch (Exception $e) {
        log_message('ERROR', "Profile update failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to save changes. Please try again.']);
    }
    exit;
}

// Fetch owner details
$stmt = $db->prepare("SELECT * FROM owners WHERE id = ?");
$stmt->execute([$user_id]);
$owner = $stmt->fetch();
?>

<div class="profile-container">
    <h2>🏢 Manage Company Profile</h2>
    <p style="color:var(--gray); margin-bottom:24px;">Update details for your charging station business and billing details.</p>

    <div class="dashboard-section-card">
        <form id="owner-profile-form" method="POST" onsubmit="saveProfile(event)" enctype="multipart/form-data">
            <h3 style="margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Business Information</h3>
            <div class="form-grid">
                <div class="form-control-group">
                    <label for="company_name">Company / Business Name *</label>
                    <input type="text" id="company_name" name="company_name" required value="<?php echo htmlspecialchars($owner['company_name']); ?>">
                </div>

                <div class="form-control-group">
                    <label for="name">Contact Person Name *</label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($owner['name'] ?? ''); ?>">
                </div>

                <div class="form-control-group">
                    <label for="phone">Phone Number *</label>
                    <input type="text" id="phone" name="phone" required value="<?php echo htmlspecialchars($owner['phone'] ?? ''); ?>">
                </div>

                <div class="form-control-group">
                    <label for="email">Business Email (Read-only)</label>
                    <input type="email" id="email" readonly value="<?php echo htmlspecialchars($owner['email']); ?>" style="background-color: var(--light); color: var(--gray); cursor: not-allowed;">
                </div>
            </div>

            <h3 style="margin: 24px 0 16px 0; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Bank Details (Payout Setup)</h3>
            <div class="form-grid">
                <div class="form-control-group">
                    <label for="bank_name">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name" placeholder="e.g. Nabil Bank" value="<?php echo htmlspecialchars($owner['bank_name'] ?? ''); ?>">
                </div>

                <div class="form-control-group">
                    <label for="bank_account_number">Bank Account Number</label>
                    <input type="text" id="bank_account_number" name="bank_account_number" placeholder="Account Number" value="<?php echo htmlspecialchars($owner['bank_account_number'] ?? ''); ?>">
                </div>

                <div class="form-control-group form-full">
                    <label for="account_holder_name">Account Holder Name</label>
                    <input type="text" id="account_holder_name" name="account_holder_name" placeholder="Name as in bank records" value="<?php echo htmlspecialchars($owner['account_holder_name'] ?? ''); ?>">
                </div>

                <div class="form-control-group form-full">
                    <label for="description">Company Description</label>
                    <textarea id="description" name="description" rows="3" placeholder="Tell us about your company..."><?php echo htmlspecialchars($owner['description'] ?? ''); ?></textarea>
                </div>
            </div>

            <h3 style="margin: 24px 0 16px 0; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Company Logo</h3>
            <div style="display:flex; align-items:center; gap:20px; margin-bottom:24px;">
                <?php
                $logoPath = '../assets/img/default-avatar.svg';
                $logoAbsolute = PUBLIC_PATH . "/assets/uploads/pfp/owner_{$user_id}.jpg";
                if (file_exists($logoAbsolute)) {
                    $logoPath = "../assets/uploads/pfp/owner_{$user_id}.jpg";
                }
                ?>
                <img src="<?php echo htmlspecialchars($logoPath); ?>?t=<?php echo time(); ?>" alt="Logo" style="width:90px; height:90px; border-radius:50%; object-fit:cover; border:3px solid #34C759;">
                <div>
                    <h4 style="margin-bottom:6px;">Upload Logo</h4>
                    <input type="file" name="logo" accept="image/*" style="font-size:12px;">
                    <p style="font-size:11px; color:var(--gray); margin-top:4px;">PNG, JPG, or GIF up to 5MB.</p>
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Profile Settings
                </button>
            </div>
        </form>
    </div>
</div>

