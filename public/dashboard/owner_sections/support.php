<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

$stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_type = 'owner' AND user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$user_id]);
$tickets = $stmt->fetchAll();

$badgeColor = function ($s) {
    return ['open' => '#f59e0b', 'in_progress' => '#3b82f6', 'resolved' => '#22c55e'][$s] ?? '#999';
};
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Help & Support</h1>
        <p>Resources and assistance for station owners</p>
    </div>
</div>

<div class="card" style="padding:24px;margin-top:20px;max-width:720px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-life-ring" style="color:#34C759;"></i> Submit a Ticket</h3>
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Category</label>
        <select id="st-category" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
            <option value="general">General question</option>
            <option value="station_approval">Station approval</option>
            <option value="payout">Payment / payout</option>
            <option value="charger_management">Charger management</option>
            <option value="account">Account issue</option>
            <option value="other">Other</option>
        </select>
    </div>
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Subject</label>
        <input type="text" id="st-subject" maxlength="150" placeholder="Short summary" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
    </div>
    <div class="form-group" style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Message</label>
        <textarea id="st-message" maxlength="5000" rows="5" placeholder="Describe the problem…" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);resize:vertical;"></textarea>
    </div>
    <button type="button" class="btn-primary" id="st-submit" style="background:#34C759;border:none;color:#fff;padding:10px 20px;border-radius:var(--radius);font-weight:600;">Send Ticket</button>
    <span id="st-feedback" style="margin-left:12px;font-size:13px;"></span>
</div>

<div class="card" style="padding:24px;margin-top:20px;max-width:720px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-inbox"></i> My Tickets</h3>
    <?php if (!$tickets): ?>
        <p style="color:var(--gray);font-size:13px;">No tickets yet — submit one above and our team will respond here.</p>
    <?php else: foreach ($tickets as $t):
        $bc = $badgeColor($t['status']); ?>
        <div style="border:1px solid var(--input);border-radius:var(--radius);padding:14px;margin-bottom:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <strong style="font-size:14px;"><?php echo htmlspecialchars($t['subject']); ?></strong>
                <span style="background:<?php echo $bc; ?>;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;text-transform:uppercase;"><?php echo htmlspecialchars($t['status']); ?></span>
            </div>
            <div style="color:var(--gray);font-size:12px;margin-bottom:6px;">
                <?php echo htmlspecialchars($t['created_at']); ?> · <?php echo htmlspecialchars($t['category']); ?>
            </div>
            <p style="font-size:13px;white-space:pre-wrap;"><?php echo htmlspecialchars($t['message']); ?></p>
            <?php if ($t['admin_reply'] !== null): ?>
                <div style="background:rgba(52,199,89,.08);border-left:3px solid #34C759;padding:10px 12px;margin-top:10px;border-radius:4px;">
                    <div style="font-size:12px;font-weight:700;color:#34C759;margin-bottom:4px;"><i class="fas fa-headset"></i> Support Team · <?php echo htmlspecialchars($t['replied_at']); ?></div>
                    <p style="font-size:13px;white-space:pre-wrap;"><?php echo htmlspecialchars($t['admin_reply']); ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
</div>

<script>
(function () {
    var btn = document.getElementById('st-submit');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var payload = {
            action: 'create',
            user_type: 'owner',
            category: document.getElementById('st-category').value,
            subject: document.getElementById('st-subject').value,
            message: document.getElementById('st-message').value
        };
        btn.disabled = true;
        fetch('/EE/api/support.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                btn.disabled = false;
                if (res.status === 'success') {
                    showToast('Ticket submitted! We will reply here.', 'success');
                    loadSection('support', true);
                } else {
                    showToast(res.message || 'Failed to submit ticket.', 'error');
                }
            })
            .catch(function () { btn.disabled = false; showToast('Network error. Please try again.', 'error'); });
    });
})();
</script>
