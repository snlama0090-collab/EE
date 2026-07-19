<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->query("SELECT id, email, name, phone, car_model, status, created_at, 'driver' as role FROM users ORDER BY created_at DESC LIMIT 50");
$drivers = $stmt->fetchAll();

$stmt = $db->query("SELECT id, email, company_name as name, phone, status, created_at, 'owner' as role FROM owners ORDER BY created_at DESC LIMIT 50");
$owners = $stmt->fetchAll();

$users = array_merge($drivers, $owners);
usort($users, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Users & Owners</h1>
        <p>Manage all platform users and station owners</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('users')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Users</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.querySelector('.badge-info')?'':'none'});">Drivers</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.querySelector('.badge-owner')?'':'none'});">Owners</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='active'?'':'none'});">Active</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='inactive'?'':'none'});">Inactive</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search users..." id="user-search" oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-columns"></i> Columns</button>
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Name <i class="fas fa-sort"></i></th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><div class="cell-avatar"><div class="avatar"><?php echo strtoupper(substr($u['name'], 0, 2)); ?></div><div class="info"><div class="name"><?php echo htmlspecialchars($u['name']); ?></div></div></div></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="badge <?php echo $u['role'] === 'driver' ? 'badge-info' : 'badge-owner'; ?>" style="<?php echo $u['role'] === 'owner' ? 'background:#fef3c7;color:#92400e;border-color:#fde68a;' : ''; ?>"><?php echo $u['role']; ?></span></td>
                <td><?php echo htmlspecialchars($u['phone'] ?? '-'); ?></td>
                <td><span class="badge badge-<?php echo $u['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo $u['status']; ?></span></td>
                <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <select><option>10</option><option>25</option><option>50</option></select> of <?php echo count($users); ?> results</div>
        <div class="pagination">
            <button disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <button class="active">1</button>
            <button disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
<task_progress>
- [x] Read all listing section files
- [x] Add modern listing CSS to dashboard.css
- [x] Refactor admin_sections/users.php
- [ ] Refactor admin_sections/stations.php
- [ ] Refactor admin_sections/reviews.php
- [ ] Refactor admin_sections/overview.php tables
- [ ] Refactor sections/bookings.php
</task_progress>
</write_to_file>