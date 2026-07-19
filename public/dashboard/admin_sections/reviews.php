<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

$stmt = $db->prepare("
    SELECT rr.*, u.name as user_name, s.name as station_name
    FROM ratings_reviews rr
    JOIN users u ON rr.user_id = u.id
    JOIN stations s ON rr.station_id = s.id
    WHERE rr.is_deleted = FALSE
    ORDER BY rr.created_at DESC
    LIMIT 50
");
$stmt->execute();
$reviews = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Recent Reviews</h1>
        <p>User feedback and flagged content moderation</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('reviews')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Reviews</span>
</div>

<div class="filter-pills">
    <button class="filter-pill active" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>r.style.display='');">All</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-danger');r.style.display=s&&s.textContent.trim()==='Flagged'?'':'none'});">Flagged</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{const s=r.querySelector('.badge-success');r.style.display=s&&s.textContent.trim()==='Clean'?'':'none'});">Clean</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{stars=r.querySelectorAll('.star');if(stars.length){r.style.display=stars.length>=4?'':'none'}});">4★+</button>
    <button class="filter-pill" onclick="document.querySelectorAll('.filter-pill').forEach(p=>p.classList.remove('active'));this.classList.add('active');document.querySelectorAll('.listing-table tbody tr').forEach(r=>{stars=r.querySelectorAll('.star');if(stars.length){r.style.display=stars.length<=2?'':'none'}});">2★-</button>
</div>

<div class="listing-toolbar">
    <div class="toolbar-search">
        <input type="text" placeholder="Search reviews..." id="review-search" oninput="var q=this.value.toLowerCase();document.querySelectorAll('.listing-table tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});">
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-columns"></i> Columns</button>
        <button type="button" class="btn btn-secondary btn-sm"><i class="fas fa-download"></i> Export</button>
    </div>
</div>

<div class="listing-table">
    <table>
        <thead>
            <tr><th>User <i class="fas fa-sort"></i></th><th>Station</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php foreach ($reviews as $r): ?>
            <tr>
                <td><div class="cell-avatar"><div class="avatar"><?php echo strtoupper(substr($r['user_name'], 0, 2)); ?></div><div class="info"><div class="name"><?php echo htmlspecialchars($r['user_name']); ?></div></div></div></td>
                <td><?php echo htmlspecialchars($r['station_name']); ?></td>
                <td><?php for($i=0;$i<$r['rating'];$i++){echo '<span class="star" style="color:var(--warning);font-size:14px;">★</span>';} ?></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(mb_substr($r['comment'] ?? '', 0, 60)) . (mb_strlen($r['comment'] ?? '') > 60 ? '...' : ''); ?></td>
                <td><?php echo $r['is_flagged'] ? '<span class="badge badge-danger">Flagged</span>' : '<span class="badge badge-success">Clean</span>'; ?></td>
                <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Showing <select><option>10</option><option>25</option><option>50</option></select> of <?php echo count($reviews); ?> results</div>
        <div class="pagination">
            <button disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <button class="active">1</button>
            <button disabled>Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
