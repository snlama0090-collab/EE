<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("
    SELECT b.*, s.name as station_name, u.name as user_name, o.company_name as owner_company
    FROM bookings b
    JOIN chargers c ON b.charger_id = c.id
    JOIN stations s ON c.station_id = s.id
    JOIN users u ON b.user_id = u.id
    JOIN owners o ON s.owner_id = o.id
    WHERE b.payment_status IN ('completed', 'pending')
    ORDER BY b.created_at DESC
    LIMIT 100
");
$invoices = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Invoices & Billing</h1>
        <p>View all payment transactions and billing records</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('invoices')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Invoices</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='completed'?'':'none'});">Paid</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-info');r.style.display=s&&s.textContent.trim()==='pending'?'':'none'});">Pending</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search invoices..." oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Booking ID</th><th>User</th><th>Station</th><th>Owner</th><th class="amount">Amount</th><th>Payment</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php if (count($invoices) > 0): ?>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td>#<?php echo $inv['id']; ?></td>
                    <td><?php echo htmlspecialchars($inv['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['station_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['owner_company']); ?></td>
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