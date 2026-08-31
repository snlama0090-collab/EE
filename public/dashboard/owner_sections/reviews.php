<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Reviews across this owner's stations (flagged first; deleted excluded)
$stmt = $db->prepare("
    SELECT rr.id, rr.rating, rr.comment, rr.created_at, rr.is_flagged, rr.flag_reason,
           u.name AS user_name, s.name AS station_name
    FROM ratings_reviews rr
    JOIN stations s ON rr.station_id = s.id
    JOIN users u ON rr.user_id = u.id
    WHERE s.owner_id = ? AND rr.is_deleted = FALSE
    ORDER BY rr.is_flagged DESC, rr.created_at DESC
");
$stmt->execute([$user_id]);
$reviews = $stmt->fetchAll();
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Reviews</h1>
        <p>Customer feedback on your stations</p>
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

<?php if (empty($reviews)): ?>
    <div class="dashboard-section-card" style="text-align:center; padding:40px;">
        <p style="color:var(--muted-foreground); margin-bottom:16px;">No reviews yet. They will appear here after drivers complete charging sessions at your stations.</p>
    </div>
<?php else: ?>
<div class="listing-table">
    <table>
        <thead>
            <tr><th>Reviewer</th><th>Station</th><th>Rating</th><th>Comment</th><th>Status</th><th>Date</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($reviews as $r): ?>
            <tr>
                <td><div class="cell-avatar"><div class="avatar"><?php echo strtoupper(substr($r['user_name'], 0, 2)); ?></div><div class="info"><div class="name"><?php echo htmlspecialchars($r['user_name']); ?></div></div></div></td>
                <td><?php echo htmlspecialchars($r['station_name']); ?></td>
                <td><?php for ($i = 0; $i < (int) $r['rating']; $i++) { echo '<span class="star" style="color:var(--warning);font-size:14px;">★</span>'; } ?></td>
                <td style="max-width:240px;"><?php echo htmlspecialchars($r['comment'] ?? ''); ?></td>
                <td>
                    <?php if ($r['is_flagged']): ?>
                        <span class="badge badge-danger">Flagged</span>
                        <div style="font-size:11px;color:var(--muted-foreground);max-width:160px;"><?php echo htmlspecialchars($r['flag_reason']); ?></div>
                    <?php else: ?>
                        <span class="badge badge-success">Visible</span>
                    <?php endif; ?>
                </td>
                <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                <td>
                    <?php if (!$r['is_flagged']): ?>
                        <button class="btn btn-danger btn-sm" onclick="flagReview(<?php echo $r['id']; ?>)">Flag</button>
                    <?php else: ?>
                        <span style="font-size:11px;color:var(--muted-foreground);">Awaiting moderation</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Flag reason modal — JS lives in the owner shell -->
<div id="flag-modal" class="modal-overlay">
  <div class="modal-box">
    <h3 style="margin:0 0 4px 0;">Flag this review</h3>
    <p style="font-size:12px;color:var(--muted-foreground);margin:0 0 12px 0;">The review stays visible until an admin reviews your flag.</p>
    <textarea id="flag-reason" rows="3" maxlength="255" placeholder="Why are you flagging this review?" style="width:100%;box-sizing:border-box;margin-bottom:12px;"></textarea>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="btn btn-sm" onclick="closeFlagModal()">Cancel</button>
      <button class="btn btn-danger btn-sm" id="flag-submit" onclick="submitFlag()">Submit flag</button>
    </div>
  </div>
</div>
<?php endif; ?>