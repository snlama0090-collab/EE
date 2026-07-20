<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Fetch financial data from stations
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

$revenue = floatval($stats['total_revenue'] ?? 0);
$kwh = floatval($stats['total_kwh'] ?? 0);
$bookings = intval($stats['total_bookings'] ?? 0);
$stations_count = intval($stats['stations_count'] ?? 0);

// Mock platform fee (30% commission)
$platform_fee = $revenue * 0.30;
$net_revenue = $revenue - $platform_fee;
$profit_margin = $revenue > 0 ? round(($net_revenue / $revenue) * 100, 1) : 0;
$avg_revenue_per_booking = $bookings > 0 ? $revenue / $bookings : 0;
?>

<!-- METRICS GRID -->
<div class="metrics-grid">
    <div class="metric-card success">
        <div class="metric-icon"><i class="fas fa-dollar-sign"></i></div>
        <div class="metric-info">
            <h3>Gross Revenue</h3>
            <p>NPR <?php echo number_format($revenue, 2); ?></p>
        </div>
    </div>
    <div class="metric-card warning">
        <div class="metric-icon"><i class="fas fa-percent"></i></div>
        <div class="metric-info">
            <h3>Platform Fee (30%)</h3>
            <p>NPR <?php echo number_format($platform_fee, 2); ?></p>
        </div>
    </div>
    <div class="metric-card">
        <div class="metric-icon"><i class="fas fa-wallet"></i></div>
        <div class="metric-info">
            <h3>Net Revenue</h3>
            <p>NPR <?php echo number_format($net_revenue, 2); ?></p>
        </div>
    </div>
    <div class="metric-card danger">
        <div class="metric-icon"><i class="fas fa-chart-line"></i></div>
        <div class="metric-info">
            <h3>Profit Margin</h3>
            <p><?php echo $profit_margin; ?>%</p>
            <div class="trend"><?php echo $profit_margin >= 50 ? '<i class="fas fa-check-circle" style="color:var(--success);"></i> Healthy' : '<i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i> Needs improvement'; ?></div>
        </div>
    </div>
</div>

<div class="dashboard-section-card">
    <div class="station-header">
        <h2><i class="fas fa-chart-bar" style="margin-right:8px;color:var(--muted-foreground);"></i> Revenue Breakdown</h2>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-sm btn-secondary" onclick="switchFinancialView('days')" id="view-days">Days</button>
            <button class="btn btn-sm btn-primary" onclick="switchFinancialView('months')" id="view-months">Months</button>
            <button class="btn btn-sm btn-secondary" onclick="switchFinancialView('years')" id="view-years">Years</button>
        </div>
    </div>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
        <div class="chart-wrapper">
            <h4 style="margin-bottom:12px;">Revenue vs Platform Fee</h4>
            <canvas id="revenueChart"></canvas>
        </div>
        <div class="chart-wrapper">
            <h4 style="margin-bottom:12px;">Energy Consumption (kWh)</h4>
            <canvas id="kwhChart"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-section-card">
    <h2><i class="fas fa-clipboard-list" style="margin-right:8px;color:var(--muted-foreground);"></i> Key Metrics Summary</h2>
    <div class="table-responsive">
        <table>
            <thead>
                <tr><th>Metric</th><th>Value</th><th>Per Station Avg</th></tr>
            </thead>
            <tbody>
                <tr><td>Total Bookings</td><td><strong><?php echo $bookings; ?></strong></td><td><?php echo $stations_count > 0 ? round($bookings / $stations_count, 1) : 0; ?></td></tr>
                <tr><td>Total kWh Consumed</td><td><strong><?php echo number_format($kwh, 2); ?> kWh</strong></td><td><?php echo $stations_count > 0 ? round($kwh / $stations_count, 2) : 0; ?> kWh</td></tr>
                <tr><td>Avg Revenue / Booking</td><td><strong>NPR <?php echo number_format($avg_revenue_per_booking, 2); ?></strong></td><td>—</td></tr>
                <tr><td>Revenue per kWh</td><td><strong>NPR <?php echo $kwh > 0 ? number_format($revenue / $kwh, 2) : 0; ?></strong></td><td>—</td></tr>
            </tbody>
        </table>
    </div>
</div>
