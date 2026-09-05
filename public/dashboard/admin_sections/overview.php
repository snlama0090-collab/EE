<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
$user_count = $stmt->fetch();

$stmt = $db->query("SELECT COUNT(*) as total FROM owners WHERE status = 'active'");
$owner_count = $stmt->fetch();

// Bookable stations only — same eligibility definition as api/stations.php's
// nearby search: approved AND is_active AND not deactivated. Pending, rejected,
// and deactivated stations are counted in their own cards, never here.
$stmt = $db->query("SELECT COUNT(*) as total FROM stations
                    WHERE approval_status = 'approved' AND is_active = TRUE AND deactivated_at IS NULL");
$station_count = $stmt->fetch();

$stmt = $db->query("SELECT COUNT(*) as total FROM stations WHERE deactivated_at IS NOT NULL");
$deactivated_count = $stmt->fetch();

$stmt = $db->query("SELECT COUNT(*) as total FROM stations WHERE approval_status = 'pending'");
$pending_approvals = $stmt->fetch();

$stmt = $db->query("SELECT COUNT(*) as total FROM ratings_reviews WHERE is_flagged = TRUE AND is_deleted = FALSE");
$flagged_reviews = $stmt->fetch();

$stmt = $db->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 10");
$activities = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT s.*, o.company_name FROM stations s
    JOIN owners o ON s.owner_id = o.id
    WHERE s.approval_status = 'pending'
    ORDER BY s.created_at DESC LIMIT 5
");
$stmt->execute();
$pending_stations = $stmt->fetchAll();
?>

<div class="dashboard-header">
    <div class="header-title">
        <h1>Dashboard</h1>
        <p>Welcome back, Admin</p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" onclick="location.reload()">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="metrics-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Active Users</span>
            <span class="stat-icon"><i class="fas fa-users"></i></span>
        </div>
        <div class="stat-value"><?php echo $user_count['total']; ?></div>
        <div class="stat-subtitle">EV drivers</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Station Owners</span>
            <span class="stat-icon"><i class="fas fa-building"></i></span>
        </div>
        <div class="stat-value"><?php echo $owner_count['total']; ?></div>
        <div class="stat-subtitle">Active companies</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Stations</span>
            <span class="stat-icon"><i class="fas fa-charging-station"></i></span>
        </div>
        <div class="stat-value"><?php echo $station_count['total']; ?></div>
        <div class="stat-subtitle">Approved &amp; active</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Deactivated Stations</span>
            <span class="stat-icon"><i class="fas fa-ban"></i></span>
        </div>
        <div class="stat-value"><?php echo $deactivated_count['total']; ?></div>
        <div class="stat-subtitle">Excluded from booking</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Pending Approvals</span>
            <span class="stat-icon"><i class="fas fa-hourglass-end"></i></span>
        </div>
        <div class="stat-value"><?php echo $pending_approvals['total']; ?></div>
        <div class="stat-subtitle">Stations awaiting approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Flagged Reviews</span>
            <span class="stat-icon"><i class="fas fa-flag"></i></span>
        </div>
        <div class="stat-value"><?php echo $flagged_reviews['total']; ?></div>
        <div class="stat-subtitle">Awaiting moderation</div>
    </div>
</div>

<div class="listing-table" style="margin-bottom:24px;">
    <div style="padding:16px 16px 0;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="font-size:15px;font-weight:600;color:var(--foreground);display:flex;align-items:center;gap:8px;"><i class="fas fa-hourglass-half" style="color:var(--muted-foreground);font-size:16px;"></i> Pending Station Approvals</h3>
    </div>
    <table>
        <thead>
            <tr><th>Station</th><th>Owner</th><th>Chargers</th><th>Requested</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php if (count($pending_stations) > 0): ?>
                <?php foreach ($pending_stations as $station): ?>
                <tr>
                    <td><div class="cell-avatar"><div class="avatar"><i class="fas fa-charging-station"></i></div><div class="info"><div class="name"><?php echo htmlspecialchars($station['name']); ?></div></div></div></td>
                    <td><?php echo htmlspecialchars($station['company_name']); ?></td>
                    <td><?php echo $station['num_chargers']; ?></td>
                    <td><?php echo date('M d, Y', strtotime($station['created_at'])); ?></td>
                    <td>
                        <button class="btn btn-sm btn-secondary" onclick="parent.viewStationDetails(<?php echo $station['id']; ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" style="text-align:center;color:var(--muted-foreground);padding:24px;font-size:13px;">No pending approvals</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="listing-table">
    <div style="padding:16px 16px 0;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="font-size:15px;font-weight:600;color:var(--foreground);display:flex;align-items:center;gap:8px;"><i class="fas fa-history" style="color:var(--muted-foreground);font-size:16px;"></i> Recent Activities</h3>
    </div>
    <table>
        <thead><tr><th>Admin</th><th>Action</th><th>Resource</th><th>Time</th></tr></thead>
        <tbody>
            <?php foreach ($activities as $activity): ?>
            <tr>
                <td><?php echo $activity['admin_id'] ? 'Admin #'.$activity['admin_id'] : 'System'; ?></td>
                <td><?php echo htmlspecialchars($activity['action']); ?></td>
                <td><?php echo htmlspecialchars($activity['resource_type']); ?></td>
                <td><?php echo date('M d, g:i A', strtotime($activity['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($activities); ?> results</div>
        <div class="pagination">
            <button disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <button class="active">1</button>
            <button disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
