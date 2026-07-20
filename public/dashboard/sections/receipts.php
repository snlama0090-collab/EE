<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, s.address, c.charger_type, c.wattage_kw,
           cs.kwh_consumed, cs.actual_charge_time_minutes, cs.start_time, cs.end_time
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
    WHERE b.user_id = ? AND b.payment_status = 'completed'
    ORDER BY b.created_at DESC
    LIMIT 100
");
$stmt->execute([$user_id]);
$receipts = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>My Receipts</h1>
        <p>Payment receipts and billing history for completed sessions</p>
    </div>
    <div class="listing-actions">
        <button class="btn btn-secondary" onclick="loadSection('receipts')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('dashboard'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Receipts</span>
</div>

<?php if (count($receipts) > 0): ?>
<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search receipts..." oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Station</th><th>Charger</th><th>Duration</th><th>Energy</th><th class="amount">Amount</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php foreach ($receipts as $r): ?>
            <tr>
                <td>
                    <div class="cell-avatar">
                        <div class="avatar"><i class="fas fa-charging-station"></i></div>
                        <div class="info">
                            <div class="name"><?php echo htmlspecialchars($r['station_name']); ?></div>
                            <div class="sub"><?php echo htmlspecialchars($r['address']); ?></div>
                        </div>
                    </div>
                </td>
                <td><?php echo htmlspecialchars($r['charger_type']); ?> (<?php echo $r['wattage_kw']; ?>kW)</td>
                <td><?php echo $r['actual_charge_time_minutes'] ? $r['actual_charge_time_minutes'] . ' min' : '-'; ?></td>
                <td><?php echo $r['kwh_consumed'] ? number_format($r['kwh_consumed'], 2) . ' kWh' : '-'; ?></td>
                <td class="amount">NPR <?php echo number_format($r['payment_amount'] ?? $r['estimated_total_cost'], 2); ?></td>
                <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($receipts); ?> results</div>
    </div>
</div>
<?php else: ?>
<div class="listing-table">
    <div style="text-align:center;padding:48px 0;color:var(--muted-foreground);">
        <i class="fas fa-receipt" style="font-size:64px;margin-bottom:16px;opacity:0.3;"></i>
        <h3 style="color:var(--foreground);">No Receipts Yet</h3>
        <p style="font-size:13px;margin-bottom:16px;">Complete a charging session to see your receipts here.</p>
        <button class="btn btn-primary btn-sm" onclick="loadSection('find-stations')">
            Find a Station
        </button>
    </div>
</div>
<?php endif; ?>
</write_to_file>