<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Support Tickets</h1>
        <p>User-submitted tickets across the platform</p>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Support</span>
</div>

<?php
$db = getDB();
$sql = "SELECT t.*, COALESCE(u.name, o.name) AS submitter_name,
               COALESCE(u.email, o.email) AS submitter_email
        FROM support_tickets t
        LEFT JOIN users u  ON t.user_type = 'driver' AND u.id = t.user_id
        LEFT JOIN owners o ON t.user_type = 'owner'  AND o.id = t.user_id
        ORDER BY FIELD(t.status,'open','in_progress','resolved'), t.created_at DESC
        LIMIT 200";
$rows = $db->prepare($sql);
$rows->execute();
$tickets = $rows->fetchAll();

$badgeColor = ['open' => '#f59e0b', 'in_progress' => '#3b82f6', 'resolved' => '#22c55e'];
?>
<div style="display:flex;gap:8px;margin-bottom:16px;" id="stf-chips">
    <button type="button" class="btn-ghost" data-f="all" onclick="supportFilter('all')">All</button>
    <button type="button" class="btn-ghost" data-f="open" onclick="supportFilter('open')">Open</button>
    <button type="button" class="btn-ghost" data-f="in_progress" onclick="supportFilter('in_progress')">In Progress</button>
    <button type="button" class="btn-ghost" data-f="resolved" onclick="supportFilter('resolved')">Resolved</button>
</div>

<?php if (!$tickets): ?>
    <div class="card" style="padding:24px;"><p style="color:var(--gray);font-size:13px;">No support tickets submitted yet.</p></div>
<?php else: foreach ($tickets as $t):
    $bc = $badgeColor[$t['status']] ?? '#999';
    $tid = (int) $t['id'];
?>
<div class="card st-ticket" data-status="<?php echo htmlspecialchars($t['status']); ?>" style="padding:20px;margin-bottom:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <strong>#<?php echo $tid; ?> · <?php echo htmlspecialchars($t['subject']); ?></strong>
        <span style="background:<?php echo $bc; ?>;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;text-transform:uppercase;"><?php echo htmlspecialchars($t['status']); ?></span>
    </div>
    <div style="color:var(--gray);font-size:12px;margin-bottom:8px;">
        <?php echo htmlspecialchars($t['submitter_name'] ?: 'Unknown'); ?> (<?php echo htmlspecialchars($t['user_type']); ?> · <?php echo htmlspecialchars($t['submitter_email'] ?: '—'); ?>)
        · <?php echo htmlspecialchars($t['created_at']); ?> · <?php echo htmlspecialchars($t['category']); ?>
    </div>
    <p style="font-size:13px;white-space:pre-wrap;"><?php echo htmlspecialchars($t['message']); ?></p>

    <?php if ($t['admin_reply'] !== null): ?>
        <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;margin-top:10px;border-radius:4px;">
            <div style="font-size:12px;font-weight:700;color:#3b82f6;margin-bottom:4px;"><i class="fas fa-headset"></i> Admin reply · <?php echo htmlspecialchars($t['replied_at']); ?></div>
            <p style="font-size:13px;white-space:pre-wrap;"><?php echo htmlspecialchars($t['admin_reply']); ?></p>
        </div>
    <?php endif; ?>

    <textarea id="st-reply-<?php echo $tid; ?>" rows="2" maxlength="5000" placeholder="Write a reply to the user…" style="width:100%;margin-top:10px;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:13px;background:var(--card);color:var(--foreground);resize:vertical;"></textarea>
    <div style="display:flex;gap:8px;margin-top:8px;">
        <button type="button" class="btn-primary" style="background:#3b82f6;border:none;color:#fff;padding:6px 14px;border-radius:var(--radius);font-weight:600;"
                onclick="supportReply(<?php echo $tid; ?>)">Send Reply</button>
        <?php if ($t['status'] !== 'resolved'): ?>
        <button type="button" class="btn-ghost" style="padding:6px 14px;"
                onclick="supportResolve(<?php echo $tid; ?>)">Mark Resolved</button>
        <?php endif; ?>
    </div>
    <span id="st-msg-<?php echo $tid; ?>" style="font-size:12px;color:var(--gray);"></span>
</div>
<?php endforeach; endif; ?>

<script>
function supportPost(payload, tid, doneMsg) {
    fetch('/EE/api/support.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var m = document.getElementById('st-msg-' + tid);
            if (m) m.textContent = res.status === 'success' ? doneMsg : (res.message || 'Failed.');
            if (typeof showToast === 'function') showToast(res.message || doneMsg, res.status === 'success' ? 'success' : 'error');
            if (res.status === 'success' && typeof loadSection === 'function') setTimeout(function () { loadSection('support', true); }, 800);
        })
        .catch(function () { if (typeof showToast === 'function') showToast('Network error.', 'error'); });
}
function supportReply(tid) {
    var el = document.getElementById('st-reply-' + tid);
    var v = el ? el.value.trim() : '';
    if (!v) { if (el) el.focus(); return; }
    supportPost({ action: 'reply', ticket_id: tid, reply: v }, tid, 'Reply sent.');
}
function supportResolve(tid) {
    supportPost({ action: 'set_status', ticket_id: tid, status: 'resolved' }, tid, 'Ticket resolved.');
}
function supportFilter(f) {
    document.querySelectorAll('.st-ticket').forEach(function (c) {
        c.style.display = (f === 'all' || c.getAttribute('data-status') === f) ? '' : 'none';
    });
}
</script>
<div class="card" style="margin-top:24px;padding:24px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-info-circle"></i> System Information</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
        <div><strong>Platform Version:</strong> 1.0.0</div>
        <div><strong>Environment:</strong> <?php echo ENV; ?></div>
        <div><strong>Database:</strong> <?php echo DB_NAME; ?></div>
        <div><strong>Timezone:</strong> Asia/Kathmandu</div>
    </div>
</div>
