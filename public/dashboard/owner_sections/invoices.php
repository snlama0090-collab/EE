<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, u.name as user_name, c.charger_type
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    JOIN users u ON b.user_id = u.id
    WHERE s.owner_id = ? AND b.payment_status IN ('completed', 'pending')
    ORDER BY b.created_at DESC
    LIMIT 100
");
$stmt->execute([$user_id]);
$invoices = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COALESCE(SUM(payment_amount), 0) as total_paid FROM bookings b JOIN chargers c ON b.charger_id = c.id JOIN stations s ON c.station_id = s.id WHERE s.owner_id = ? AND b.payment_status = 'completed'");
$stmt->execute([$user_id]);
$total_paid = $stmt->fetch()['total_paid'];

$stmt = $db->prepare("SELECT COALESCE(SUM(estimated_total_cost), 0) as total_pending FROM bookings b JOIN chargers c ON b.charger_id = c.id JOIN stations s ON c.station_id = s.id WHERE s.owner_id = ? AND b.payment_status = 'pending'");
$stmt->execute([$user_id]);
$total_pending = $stmt->fetch()['total_pending'];
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Invoices & Billing</h1>
        <p>Revenue logs and payment records for your stations</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('invoices')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card success">
        <div class="metric-icon"><i class="fas fa-check-circle"></i></div>
        <div class="metric-info">
            <h3>Total Paid</h3>
            <p>NPR <?php echo number_format($total_paid, 2); ?></p>
        </div>
    </div>
    <div class="metric-card warning">
        <div class="metric-icon"><i class="fas fa-clock"></i></div>
        <div class="metric-info">
            <h3>Pending</h3>
            <p>NPR <?php echo number_format($total_pending, 2); ?></p>
        </div>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Booking ID</th><th>User</th><th>Station</th><th>Charger</th><th class="amount">Amount</th><th>Payment</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php if (count($invoices) > 0): ?>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td>#<?php echo $inv['id']; ?></td>
                    <td><?php echo htmlspecialchars($inv['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['station_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['charger_type']); ?></td>
                    <td class="amount">NPR <?php echo number_format($inv['payment_amount'] ?? $inv['estimated_total_cost'], 2); ?></td>
                    <td><span class="badge badge-<?php echo $inv['payment_status'] === 'completed' ? 'success' : 'info'; ?>"><?php echo $inv['payment_status']; ?></span></td>
                    <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, Y', strtotime($inv['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted-foreground);padding:24px;">No invoices found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($invoices); ?> results</div>
    </div>
</div>
</write_to_file>