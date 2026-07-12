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

$stmt = $db->prepare("
    SELECT s.*, o.company_name FROM stations s
    JOIN owners o ON s.owner_id = o.id
    WHERE s.approval_status = 'pending'
    ORDER BY s.created_at DESC LIMIT 10
");
$stmt->execute();
$pending_stations = $stmt->fetchAll();

// === Flagged Reviews Stats ===
$stmt = $db->query("SELECT COUNT(*) as total FROM ratings_reviews WHERE is_flagged = TRUE AND is_deleted = FALSE");
$flagged_count = $stmt->fetch()['total'];

$stmt = $db->prepare("
    SELECT rr.*, u.name as user_name, s.name as station_name
    FROM ratings_reviews rr
    JOIN users u ON rr.user_id = u.id
    JOIN stations s ON rr.station_id = s.id
    WHERE rr.is_flagged = TRUE AND rr.is_deleted = FALSE
    ORDER BY rr.created_at DESC LIMIT 10
");
$stmt->execute();
$flagged_reviews = $stmt->fetchAll();
?>
<h2 style="margin-bottom: 24px;"><i class="fas fa-chart-bar"></i> Reports & Analytics</h2>

<!-- Booking & Revenue Cards -->
<div class="cards-grid" style="margin-bottom: 24px;">
    <div class="card">
        <div class="card-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="card-title">Total Bookings</div>
        <div class="card-value"><?php echo number_format($total_bookings); ?></div>
        <div class="card-subtitle"><?php echo $bookings_30d; ?> in last 30 days</div>
    </div>
    <div class="card">
        <div class="card-icon"><i class="fas fa-dollar-sign"></i></div>
        <div class="card-title">Total Revenue</div>
        <div class="card-value">NPR <?php echo number_format($total_revenue, 2); ?></div>
        <div class="card-subtitle">NPR <?php echo number_format($revenue_30d, 2); ?> in last 30 days</div>
    </div>
    <div class="card">
        <div class="card-icon"><i class="fas fa-tasks"></i></div>
        <div class="card-title">Station Approvals</div>
        <div class="card-value"><?php echo $approval_counts['pending']; ?></div>
        <div class="card-subtitle">Pending · <?php echo $approval_counts['approved']; ?> approved · <?php echo $approval_counts['rejected']; ?> rejected</div>
    </div>
    <div class="card">
        <div class="card-icon"><i class="fas fa-flag"></i></div>
        <div class="card-title">Flagged Reviews</div>
        <div class="card-value"><?php echo $flagged_count; ?></div>
        <div class="card-subtitle">Awaiting moderation</div>
    </div>
</div>

<!-- Bookings per Day (last 30 days) -->
<div class="card" style="margin-bottom: 24px;">
    <h3><i class="fas fa-calendar-day"></i> Bookings per Day (Last 30 Days)</h3>
    <?php if (count($daily_bookings) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Date</th><th>Bookings</th><th>Revenue</th></tr>
                </thead>
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
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray); padding: 16px;">No bookings in the last 30 days.</p>
    <?php endif; ?>
</div>

<!-- Pending Station Approvals -->
<div class="card" style="margin-bottom: 24px;">
    <h3><i class="fas fa-hourglass-half"></i> Pending Station Approvals</h3>
    <?php if (count($pending_stations) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Station</th><th>Owner</th><th>Chargers</th><th>Submitted</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_stations as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td><?php echo htmlspecialchars($s['company_name']); ?></td>
                        <td><?php echo $s['num_chargers']; ?></td>
                        <td><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray); padding: 16px;">No pending station approvals.</p>
    <?php endif; ?>
</div>

<!-- Flagged Reviews -->
<div class="card">
    <h3><i class="fas fa-flag"></i> Recent Flagged Reviews</h3>
    <?php if (count($flagged_reviews) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>User</th><th>Station</th><th>Rating</th><th>Comment</th><th>Flagged</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($flagged_reviews as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['user_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['station_name']); ?></td>
                        <td><?php echo str_repeat('⭐', $r['rating']); ?></td>
                        <td><?php echo htmlspecialchars(mb_substr($r['comment'] ?? '', 0, 60)) . (mb_strlen($r['comment'] ?? '') > 60 ? '...' : ''); ?></td>
                        <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray); padding: 16px;">No flagged reviews.</p>
    <?php endif; ?>
</div>