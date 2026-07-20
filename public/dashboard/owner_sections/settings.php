<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

$stmt = $db->prepare("SELECT * FROM owners WHERE id = ?");
$stmt->execute([$user_id]);
$owner = $stmt->fetch();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Settings</h1>
        <p>Manage your account and station preferences</p>
    </div>
</div>

<div class="card" style="margin-bottom:24px;padding:24px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-store"></i> Company Information</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
        <div><strong>Company:</strong> <?php echo htmlspecialchars($owner['company_name']); ?></div>
        <div><strong>Email:</strong> <?php echo htmlspecialchars($owner['email']); ?></div>
        <div><strong>Phone:</strong> <?php echo htmlspecialchars($owner['phone'] ?? '-'); ?></div>
        <div><strong>Status:</strong> <span class="badge badge-success"><?php echo $owner['status']; ?></span></div>
    </div>
</div>

<div class="card" style="padding:24px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-sliders-h"></i> Station Preferences</h3>
    <p style="color:var(--gray);font-size:13px;">Configure default pricing, operating hours, and other station-level settings from the <a href="#" onclick="loadSection('stations');return false;" style="color:var(--primary);">My Stations</a> section.</p>
</div>
</write_to_file>