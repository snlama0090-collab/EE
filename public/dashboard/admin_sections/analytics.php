<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

// === Booking & Revenue Stats ===
$stmt = $db->query("SELECT COUNT(*) as total FROM bookings");
$total_bookings = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COUNT(*) as total FROM bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$bookings_30d = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COALESCE(SUM(payment_amount), 0) as total FROM bookings WHERE status = 'completed'");
$total_revenue = $stmt->fetch()['total'];

$stmt = $db->query("SELECT COALESCE(SUM(payment_amount), 0) as total FROM bookings WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$revenue_30d = $stmt->fetch()['total'];

// Last 30 days: bookings per day
$stmt = $db->query("
    SELECT DATE(created_at) as day, COUNT(*) as bookings, COALESCE(SUM(payment_amount), 0) as revenue
    FROM bookings
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day DESC
");
$daily_bookings = $stmt->fetchAll();

// === Station Approval Stats ===
$stmt = $db->query("SELECT approval_status, COUNT(*) as count FROM stations GROUP BY approval_status");
$approval_rows = $stmt->fetchAll();
$approval_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($approval_rows as $r) {
    $approval_counts[$r['approval_status']] = $r['count'];
}

// === Flagged Reviews Stats ===
$stmt = $db->query("SELECT COUNT(*) as total FROM ratings_reviews WHERE is_flagged = TRUE AND is_deleted = FALSE");
$flagged_count = $stmt->fetch()['total'];

// === Total kWh consumed ===
$stmt = $db->query("SELECT COALESCE(SUM(kwh_consumed), 0) as total FROM charging_sessions");
$total_kwh = $stmt->fetch()['total'];

// === Active charging sessions ===
$stmt = $db->query("SELECT COUNT(*) as total FROM bookings WHERE status = 'charging'");
$active_sessions = $stmt->fetch()['total'];
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Analytics</h1>
        <p>Platform-wide metrics and performance data</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('analytics')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Analytics</span>
</div>

<div class="metrics-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Bookings</span>
            <span class="stat-icon"><i class="fas fa-calendar-check"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_bookings); ?></div>
        <div class="stat-subtitle"><?php echo $bookings_30d; ?> in last 30 days</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Total Revenue</span>
            <span class="stat-icon"><i class="fas fa-dollar-sign"></i></span>
        </div>
        <div class="stat-value">NPR <?php echo number_format($total_revenue, 2); ?></div>
        <div class="stat-subtitle">NPR <?php echo number_format($revenue_30d, 2); ?> in 30d</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Energy Delivered</span>
            <span class="stat-icon"><i class="fas fa-bolt"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_kwh, 2); ?> kWh</div>
        <div class="stat-subtitle">Total platform-wide</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Active Sessions</span>
            <span class="stat-icon"><i class="fas fa-plug"></i></span>
        </div>
        <div class="stat-value"><?php echo $active_sessions; ?></div>
        <div class="stat-subtitle">Currently charging</div>
    </div>
</div>

<div class="listing-table" style="margin-bottom:24px;">
    <div style="padding:16px 16px 0;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="font-size:15px;font-weight:600;color:var(--foreground);display:flex;align-items:center;gap:8px;"><i class="fas fa-calendar-day" style="color:var(--muted-foreground);font-size:16px;"></i> Bookings per Day (Last 30 Days)</h3>
    </div>
    <?php if (count($daily_bookings) > 0): ?>
    <table>
        <thead><tr><th>Date</th><th>Bookings</th><th>Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($daily_bookings as $d): ?>
            <tr>
                <td><?php echo date('M d, Y', strtotime($d['day'])); ?></td>
                <td><?php echo $d['bookings']; ?></td>
                <td>NPR <?php echo number_format($d['revenue'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="text-align:center;color:var(--gray);padding:24px;">No data in the last 30 days.</p>
    <?php endif; ?>
</div>

<div class="metrics-grid">
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Station Approvals</span>
            <span class="stat-icon"><i class="fas fa-tasks"></i></span>
        </div>
        <div class="stat-value"><?php echo $approval_counts['pending']; ?> pending</div>
        <div class="stat-subtitle"><?php echo $approval_counts['approved']; ?> approved · <?php echo $approval_counts['rejected']; ?> rejected</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <span class="stat-label">Flagged Reviews</span>
            <span class="stat-icon"><i class="fas fa-flag"></i></span>
        </div>
        <div class="stat-value"><?php echo $flagged_count; ?></div>
        <div class="stat-subtitle">Awaiting moderation</div>
    </div>
</div>
</write_to_file>