<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 50");
$notifications = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Notifications</h1>
        <p>Platform activity and system notifications</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('notifications')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Notifications</span>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Action</th><th>Resource</th><th>Details</th><th>Time</th></tr>
        </thead>
        <tbody>
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $n): ?>
                <tr>
                    <td><span class="badge badge-info"><?php echo htmlspecialchars($n['action']); ?></span></td>
                    <td><?php echo htmlspecialchars($n['resource_type'] ?? '-'); ?> #<?php echo $n['resource_id'] ?? ''; ?></td>
                    <td style="font-size:12px;color:var(--muted-foreground);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars(is_string($n['details']) ? substr($n['details'], 0, 80) : ''); ?>
                    </td>
                    <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, H:i', strtotime($n['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" style="text-align:center;color:var(--muted-foreground);padding:24px;">No notifications yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($notifications); ?> results</div>
    </div>
</div>
</write_to_file>