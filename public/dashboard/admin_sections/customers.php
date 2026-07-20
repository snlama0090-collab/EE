<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("SELECT id, email, name, phone, car_model, charger_preference, status, created_at FROM users ORDER BY created_at DESC LIMIT 100");
$drivers = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Customers / EV Drivers</h1>
        <p>Manage all registered EV drivers on the platform</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('customers')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Customers</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='active'?'':'none'});">Active</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='inactive'?'':'none'});">Inactive</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search customers..." oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Name</th><th>Email</th><th>Phone</th><th>Vehicle</th><th>Charger Pref</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
            <?php if (count($drivers) > 0): ?>
                <?php foreach ($drivers as $u): ?>
                <tr>
                    <td>
                        <div class="cell-avatar">
                            <div class="avatar"><?php echo strtoupper(substr($u['name'], 0, 2)); ?></div>
                            <div class="info"><div class="name"><?php echo htmlspecialchars($u['name']); ?></div></div>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo htmlspecialchars($u['phone'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($u['car_model'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $u['charger_preference'] ?? 'Any')); ?></td>
                    <td><span class="badge badge-<?php echo $u['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo $u['status']; ?></span></td>
                    <td style="font-size:12px;color:var(--muted-foreground);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted-foreground);padding:24px;">No customers found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <?php echo count($drivers); ?> results</div>
    </div>
</div>
</write_to_file>