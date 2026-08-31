<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
$db = getDB();

// Pagination: page (1-based) + per_page (default 10, max 50)
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = min(50, max(1, intval($_GET['per_page'] ?? 10)));
$offset = ($page - 1) * $per_page;

// Total for pagination controls
$total = (int) $db->query("SELECT COUNT(*) AS c FROM ratings_reviews WHERE is_deleted = FALSE")->fetch()['c'];
$total_pages = (int) ceil($total / $per_page);

$stmt = $db->prepare("
    SELECT rr.*, u.name as user_name, s.name as station_name, o.company_name
    FROM ratings_reviews rr
    JOIN users u ON rr.user_id = u.id
    JOIN stations s ON rr.station_id = s.id
    JOIN owners o ON s.owner_id = o.id
    WHERE rr.is_deleted = FALSE
    ORDER BY rr.is_flagged DESC, rr.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Recent Reviews</h1>
        <p>User feedback and flagged content moderation</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('reviews', true)">
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
            <tr><th>User <i class="fas fa-sort"></i></th><th>Station</th><th>Owner</th><th>Rating</th><th>Comment</th><th>Flag Reason</th><th>Status</th><th>Date</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($reviews as $r): ?>
            <tr>
                <td><div class="cell-avatar"><div class="avatar"><?php echo strtoupper(substr($r['user_name'], 0, 2)); ?></div><div class="info"><div class="name"><?php echo htmlspecialchars($r['user_name']); ?></div></div></div></td>
                <td><?php echo htmlspecialchars($r['station_name']); ?></td>
                <td><?php echo htmlspecialchars($r['company_name'] ?? '-'); ?></td>
                <td><?php for($i=0;$i<$r['rating'];$i++){echo '<span class="star" style="color:var(--warning);font-size:14px;">★</span>';} ?></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(mb_substr($r['comment'] ?? '', 0, 60)) . (mb_strlen($r['comment'] ?? '') > 60 ? '...' : ''); ?></td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;"><?php echo $r['is_flagged'] ? htmlspecialchars($r['flag_reason'] ?? '') : '—'; ?></td>
                <td><?php echo $r['is_flagged'] ? '<span class="badge badge-danger">Flagged</span>' : '<span class="badge badge-success">Clean</span>'; ?></td>
                <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                <td><?php if ($r['is_flagged']): ?>
                    <button type="button" class="btn btn-danger btn-sm" onclick="moderateReview(<?php echo (int) $r['id']; ?>, 'remove')"><i class="fas fa-trash"></i> Remove</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="moderateReview(<?php echo (int) $r['id']; ?>, 'dismiss')"><i class="fas fa-check"></i> Dismiss</button>
                <?php else: ?><span style="color:var(--muted-foreground);font-size:12px;">—</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="listing-footer">
        <div class="rows-select">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total; ?> reviews)</div>
        <div class="pagination">
            <button <?php echo $page <= 1 ? 'disabled' : ''; ?> onclick="goToReviewPage(<?php echo $page - 1; ?>)"><i class="fas fa-chevron-left"></i> Previous</button>
            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <button <?php echo $p === $page ? 'class="active"' : ''; ?> onclick="goToReviewPage(<?php echo $p; ?>)"><?php echo $p; ?></button>
            <?php endfor; ?>
            <button <?php echo $page >= $total_pages ? 'disabled' : ''; ?> onclick="goToReviewPage(<?php echo $page + 1; ?>)">Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<script>
// Pagination: fetch the reviews fragment for a new page and swap content-area.
// We can't use ?page=N in the URL because admin.php routes ?page= as the SECTION name,
// so this stays within the reviews section and fetches the paginated fragment via AJAX.
function goToReviewPage(page) {
    const contentArea = document.getElementById('content-area');
    contentArea.innerHTML = '<div style="padding:32px;text-align:center;color:#8E8E93;"><i class="fas fa-spinner fa-spin" style="font-size:48px;display:block;margin-bottom:16px;"></i><p>Loading...</p></div>';
    fetch(`admin_sections/reviews.php?page=${page}&per_page=<?php echo $per_page; ?>`)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => { contentArea.innerHTML = html; })
        .catch(() => { contentArea.innerHTML = '<div style="padding:32px;text-align:center;color:#FF3B30;">Failed to load page.</div>'; });
}

function moderateReview(reviewId, decision) {
    var verb = decision === 'remove' ? 'remove this review' : 'dismiss this flag';
    // window.showConfirm (modal.js:87) is the house confirmation pattern —
    // same as approveStation/rejectStation. Live-verified 2026-08-29:
    // modal opens, Confirm fires the action, DB updates.
    showConfirm('Are you sure you want to ' + verb + '?', function () {
        fetch('/EE/api/reviews.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'moderate', review_id: reviewId, decision: decision })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.status === 'success') {
                showToast(res.message || 'Done.', 'success');
                loadSection('reviews', true);
            } else {
                showToast(res.message || 'Moderation action failed.', 'error');
            }
        }).catch(function () { showToast('Network error — try again.', 'error'); });
    });
}
</script>
