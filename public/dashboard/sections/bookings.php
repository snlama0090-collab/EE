<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Fetch driver bookings
$stmt = $db->prepare("
    SELECT b.*, s.name as station_name, s.address, s.city,
           c.charger_type, c.wattage_kw, 
           cs.kwh_consumed, cs.actual_charge_time_minutes, cs.start_time as session_start
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 50
");
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>My Bookings</h1>
        <p>View and manage your charging session history</p>
    </div>
    <div class="listing-actions">
        <button class="btn btn-secondary" onclick="loadSection('bookings')">
            <i class="fas fa-sync"></i> Refresh
        </button>
        <button class="btn btn-primary" onclick="loadSection('find-stations')">
            <i class="fas fa-plus"></i> New Booking
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('dashboard'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Bookings</span>
</div>

<?php if (count($bookings) > 0): ?>
<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='completed'?'':'none'});">Completed</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-warning');r.style.display=s&&s.textContent.trim()==='charging'?'':'none'});">Active</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-info');r.style.display=s&&s.textContent.trim()==='booked'?'':'none'});">Upcoming</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='cancelled'?'':'none'});">Cancelled</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search bookings..." oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Station <i class="fas fa-sort"></i></th><th>Charger</th><th>Duration</th><th class="amount">Cost</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($bookings as $booking): 
                $status = $booking['status'];
                $badge = 'badge-info';
                if ($status === 'completed') $badge = 'badge-success';
                elseif ($status === 'cancelled') $badge = 'badge-danger';
                elseif ($status === 'charging') $badge = 'badge-warning';
            ?>
            <tr>
                <td>
                    <div class="cell-avatar">
                        <div class="avatar"><i class="fas fa-charging-station"></i></div>
                        <div class="info">
                            <div class="name"><?php echo htmlspecialchars($booking['station_name']); ?></div>
                            <div class="sub"><?php echo htmlspecialchars($booking['city']); ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <div>⚡ <?php echo htmlspecialchars($booking['charger_type']); ?></div>
                    <div style="font-size:11px;color:var(--muted-foreground);"><?php echo $booking['wattage_kw']; ?> kW</div>
                </td>
                <td>
                    <?php if ($status === 'completed' && $booking['actual_charge_time_minutes']): ?>
                        <strong><?php echo $booking['actual_charge_time_minutes']; ?> mins</strong>
                        <div style="font-size:11px;color:var(--muted-foreground);"><?php echo number_format($booking['kwh_consumed'], 1); ?> kWh</div>
                    <?php else: ?>
                        <strong><?php echo $booking['calculated_charge_time_minutes']; ?> mins</strong>
                        <div style="font-size:11px;color:var(--muted-foreground);">Estimate</div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="amount">NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></span>
                    <?php if ($status === 'completed' && $booking['payment_amount']): ?>
                        <div style="font-size:10px;color:var(--success);font-weight:600;">PAID</div>
                    <?php else: ?>
                        <div style="font-size:10px;color:var(--muted-foreground);">ESTIMATE</div>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td>
                    <?php if ($status === 'booked'): ?>
                        <button class="btn btn-danger btn-sm" onclick="cancelBooking(<?php echo $booking['id']; ?>)">
                            Cancel
                        </button>
                    <?php else: ?>
                        <span style="font-size:12px;color:var(--muted-foreground);">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <select><option>10</option><option>25</option><option>50</option></select> of <?php echo count($bookings); ?> results</div>
        <div class="pagination">
            <button disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <button class="active">1</button>
            <button disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
<?php else: ?>
    <div class="listing-table">
        <div style="text-align:center;padding:48px 0;color:var(--muted-foreground);">
            <i class="fas fa-history" style="font-size:64px;margin-bottom:16px;opacity:0.3;"></i>
            <h3 style="color:var(--foreground);">No Bookings Found</h3>
            <p style="font-size:13px;margin-bottom:16px;">You have not made any bookings yet.</p>
            <button class="btn btn-primary btn-sm" onclick="loadSection('find-stations')">
                Book Your First Session
            </button>
        </div>
    </div>
<?php endif; ?>
</write_to_file>
<task_progress>
- [x] Read all listing section files
- [x] Add modern listing CSS to dashboard.css
- [x] Refactor admin_sections/users.php
- [x] Refactor admin_sections/stations.php
- [x] Refactor admin_sections/reviews.php
- [x] Refactor admin_sections/overview.php tables
- [x] Refactor sections/bookings.php
</task_progress>
</write_to_file>