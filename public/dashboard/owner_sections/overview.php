<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Fetch summary metrics
$stmt = $db->prepare("
    SELECT 
        COUNT(id) as stations_count,
        SUM(total_bookings) as total_bookings,
        SUM(total_revenue) as total_revenue,
        SUM(total_kwh_consumed) as total_kwh
    FROM stations 
    WHERE owner_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Active charging sessions count
$stmt = $db->prepare("
    SELECT COUNT(b.id) as active_sessions
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    WHERE s.owner_id = ? AND b.status = 'charging'
");
$stmt->execute([$user_id]);
$active_sessions = $stmt->fetch()['active_sessions'] ?? 0;

// Recent bookings list
$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, c.charger_number, c.charger_type, u.name as user_name
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    JOIN users u ON b.user_id = u.id
    WHERE s.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 6
");
$stmt->execute([$user_id]);
$recent_bookings = $stmt->fetchAll();
?>

<!-- METRICS CARDS -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon"><i class="fas fa-wallet"></i></div>
        <div class="metric-info">
            <h3>Total Revenue</h3>
            <p>NPR <?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
        </div>
    </div>
    
    <div class="metric-card success">
        <div class="metric-icon"><i class="fas fa-plug"></i></div>
        <div class="metric-info">
            <h3>Charging Now</h3>
            <p><?php echo $active_sessions; ?> Sessions</p>
        </div>
    </div>

    <div class="metric-card warning">
        <div class="metric-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="metric-info">
            <h3>Total Bookings</h3>
            <p><?php echo intval($stats['total_bookings'] ?? 0); ?></p>
        </div>
    </div>

    <div class="metric-card danger">
        <div class="metric-icon"><i class="fas fa-charging-station"></i></div>
        <div class="metric-info">
            <h3>My Stations</h3>
            <p><?php echo intval($stats['stations_count'] ?? 0); ?></p>
        </div>
    </div>
</div>

<div class="dashboard-section-card">
    <div class="station-header">
        <h2>⚡ Recent Bookings Activity</h2>
        <button class="btn btn-primary btn-sm" onclick="loadSection('bookings')">View All</button>
    </div>
    
    <?php if (count($recent_bookings) > 0): ?>
        <div class="listing-table">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Station</th>
                        <th>Charger Details</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_bookings as $booking): 
                        $status_badge = 'badge-info';
                        if ($booking['status'] === 'completed') $status_badge = 'badge-success';
                        elseif ($booking['status'] === 'cancelled') $status_badge = 'badge-danger';
                        elseif ($booking['status'] === 'charging') $status_badge = 'badge-warning';
                    ?>
                    <tr>
                        <td>
                            <div class="cell-avatar">
                                <div class="avatar"><?php echo strtoupper(substr($booking['user_name'], 0, 1)); ?></div>
                                <div class="info">
                                    <span class="name"><?php echo htmlspecialchars($booking['user_name']); ?></span>
                                    <span class="sub">#<?php echo $booking['id']; ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($booking['station_name']); ?></td>
                        <td>
                            <span class="charger-badge">#<?php echo $booking['charger_number']; ?></span>
                            <span style="font-size: 12px; color: var(--muted-foreground);"><?php echo htmlspecialchars($booking['charger_type']); ?></span>
                        </td>
                        <td class="amount">NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></td>
                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                        <td style="font-size: 12px; color: var(--muted-foreground);"><?php echo date('M d, H:i', strtotime($booking['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray); padding: 24px;">No recent bookings found at your stations.</p>
    <?php endif; ?>
</div>
