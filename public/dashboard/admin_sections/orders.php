<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("
    SELECT b.*, s.name as station_name, c.charger_type, c.wattage_kw,
           u.name as user_name, u.email as user_email,
           cs.kwh_consumed, cs.actual_charge_time_minutes, cs.start_time as session_start
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
    ORDER BY b.created_at DESC
    LIMIT 100
");
$bookings = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Orders & Charging Sessions</h1>
        <p>View all platform bookings and charging activity</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('orders')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Orders</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-warning');r.style.display=s&&s.textContent.trim()==='charging'?'':'none'});">Active</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='completed'?'':'none'});">Completed</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='cancelled'?'':'none'});">Cancelled</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search orders..." oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>User</th><th>Station</th><th>Charger</th><th>Duration</th><th class="amount">Cost</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php if (count($bookings) > 0): ?>
                <?php foreach ($bookings as $b):
                    $badge = 'badge-info';
                    if ($b['status'] === 'completed') $badge = 'badge-success';
                    elseif ($b['status'] === 'cancelled') $badge = 'badge-danger';
                    elseif ($b['status'] === 'charging') $badge = 'badge-warning';
                ?>
                <tr>
                    <td>
                        <div class="cell-avatar">
                            <div class="avatar"><?php echo strtoupper(substr($b['user_name'], 0, 1)); ?></div>
                            <div class="info">
                                <div class="name"><?php echo htmlspecialchars($b['user_name']); ?></div>
                                <div class="sub"><?php echo htmlspecialchars($b['user_email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($b['station_name']); ?></td>
                    <td><?php echo htmlspecialchars($b['charger_type']); ?> (<?php echo $b['wattage_kw']; ?>kW)</td>
                    <td>
                        <?php if ($b['status'] === 'completed' && $b['actual_charge_time_minutes']): ?>
                            <?php echo $b['actual_charge_time_minutes']; ?> min
                            <div style="font-size:11px;color:var(--muted-foreground);"><?php echo number_format($b['kwh_consumed'], 1); ?> kWh</div>
                        <?php else: ?>
                            <?php echo $b['calculated_charge_time_minutes']; ?> min (est)
                        <?php endif; ?>
                    </td>
                    <td class="amount">NPR <?php echo number_format($b['estimated_total_cost'], 2); ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($b['status']); ?></span></td>
                    <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, H:i', strtotime($b['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted-foreground);padding:24px;">No orders found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($bookings); ?> results</div>
    </div>
</div>
</write_to_file>