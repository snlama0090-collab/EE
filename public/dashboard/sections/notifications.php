<?php
require_once '../../../app/config/config.php';
require_once '../../../app/helpers/Auth.php';

Auth::requireUserType('driver');
$db = getDB();
$user_id = Auth::getCurrentUserId();

$stmt = $db->prepare("SELECT al.* FROM activity_logs al JOIN bookings b ON al.resource_id = b.id WHERE b.user_id = ? AND al.resource_type = 'booking' ORDER BY al.created_at DESC LIMIT 50");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Notifications</h1>
        <p>Your booking and account updates</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('notifications')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('dashboard'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Notifications</span>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Action</th><th>Details</th><th>Time</th></tr>
        </thead>
        <tbody>
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $n): ?>
                <tr>
                    <td><span class="badge badge-info"><?php echo htmlspecialchars($n['action']); ?></span></td>
                    <td style="font-size:12px;color:var(--muted-foreground);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars(substr($n['details'] ?? '', 0, 80)); ?>
                    </td>
                    <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, H:i', strtotime($n['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3" style="text-align:center;color:var(--muted-foreground);padding:24px;">No notifications yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($notifications); ?> results</div>
    </div>
</div>