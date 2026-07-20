<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

$stmt = $db->prepare("
    SELECT COUNT(id) as stations_count, SUM(total_bookings) as total_bookings,
           SUM(total_revenue) as total_revenue, SUM(total_kwh_consumed) as total_kwh
    FROM stations WHERE owner_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

$revenue = floatval($stats['total_revenue'] ?? 0);
$kwh = floatval($stats['total_kwh'] ?? 0);
$bookings = intval($stats['total_bookings'] ?? 0);
$stations_count = intval($stats['stations_count'] ?? 0);

$stmt = $db->prepare("SELECT COUNT(b.id) as active FROM bookings b JOIN chargers c ON b.charger_id = c.id JOIN stations s ON c.station_id = s.id WHERE s.owner_id = ? AND b.status = 'charging'");
$stmt->execute([$user_id]);
$active_sessions = $stmt->fetch()['active'] ?? 0;
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Analytics</h1>
        <p>Performance metrics for your stations</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('analytics')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card success">
        <div class="metric-icon"><i class="fas fa-dollar-sign"></i></div>
        <div class="metric-info">
            <h3>Total Revenue</h3>
            <p>NPR <?php echo number_format($revenue, 2); ?></p>
        </div>
    </div>
    <div class="metric-card warning">
        <div class="metric-icon"><i class="fas fa-bolt"></i></div>
        <div class="metric-info">
            <h3>Energy Delivered</h3>
            <p><?php echo number_format($kwh, 2); ?> kWh</p>
        </div>
    </div>
    <div class="metric-card">
        <div class="metric-icon"><i class="fas fa-calendar-check"></i></div>
        <div class="metric-info">
            <h3>Total Bookings</h3>
            <p><?php echo $bookings; ?></p>
        </div>
    </div>
    <div class="metric-card danger">
        <div class="metric-icon"><i class="fas fa-plug"></i></div>
        <div class="metric-info">
            <h3>Active Sessions</h3>
            <p><?php echo $active_sessions; ?></p>
        </div>
    </div>
</div>

<div class="dashboard-section-card">
    <h2>📊 Key Metrics</h2>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Metric</th><th>Value</th><th>Per Station Avg</th></tr></thead>
            <tbody>
                <tr><td>Total Bookings</td><td><strong><?php echo $bookings; ?></strong></td><td><?php echo $stations_count > 0 ? round($bookings / $stations_count, 1) : 0; ?></td></tr>
                <tr><td>Total kWh Consumed</td><td><strong><?php echo number_format($kwh, 2); ?> kWh</strong></td><td><?php echo $stations_count > 0 ? round($kwh / $stations_count, 2) : 0; ?> kWh</td></tr>
                <tr><td>Avg Revenue / Booking</td><td><strong>NPR <?php echo $bookings > 0 ? number_format($revenue / $bookings, 2) : 0; ?></strong></td><td>—</td></tr>
                <tr><td>Revenue per kWh</td><td><strong>NPR <?php echo $kwh > 0 ? number_format($revenue / $kwh, 2) : 0; ?></strong></td><td>—</td></tr>
            </tbody>
        </table>
    </div>
</div>
</write_to_file>