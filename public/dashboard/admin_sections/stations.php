<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, o.company_name FROM stations s
    JOIN owners o ON s.owner_id = o.id
    ORDER BY s.created_at DESC
");
$stmt->execute();
$stations = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>All Stations</h1>
        <p>Monitor and manage charging station registrations</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('stations')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Stations</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&(s.textContent.trim()==='approved'||s.textContent.trim()==='Active')?'':'none'});">Approved</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-warning');r.style.display=s&&s.textContent.trim()==='pending'?'':'none'});">Pending</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='rejected'?'':'none'});">Rejected</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search stations..." id="station-search" oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-columns"></i> Columns</button>
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>Station <i class="fas fa-sort"></i></th><th>Owner</th><th>City</th><th>Chargers</th><th>Status</th><th>Approval</th></tr>
        </thead>
        <tbody>
            <?php foreach ($stations as $s): ?>
            <tr>
                <td><div class="cell-avatar"><div class="avatar"><i class="fas fa-charging-station"></i></div><div class="info"><div class="name"><?php echo htmlspecialchars($s['name']); ?></div></div></div></td>
                <td><?php echo htmlspecialchars($s['company_name']); ?></td>
                <td><?php echo htmlspecialchars($s['city']); ?></td>
                <td><?php echo $s['num_chargers']; ?></td>
                <td><span class="badge badge-<?php echo $s['is_active'] ? 'success' : 'danger'; ?>"><?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                <td><span class="badge badge-<?php echo $s['approval_status'] === 'approved' ? 'success' : ($s['approval_status'] === 'pending' ? 'warning' : 'danger'); ?>"><?php echo $s['approval_status']; ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <select><option>10</option><option>25</option><option>50</option></select> of <?php echo count($stations); ?> results</div>
        <div class="pagination">
            <button disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <button class="active">1</button>
            <button disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
