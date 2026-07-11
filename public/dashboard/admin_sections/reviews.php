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
<h2 style="margin-bottom: 24px;"><i class="fas fa-star"></i> Recent Reviews</h2>

<div class="table-container">
    <table>
        <thead>
            <tr><th>User</th><th>Station</th><th>Rating</th><th>Comment</th><th>Flagged</th><th>Date</th></tr>
        </thead>
        <tbody>
            <?php foreach ($reviews as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['user_name']); ?></td>
                <td><?php echo htmlspecialchars($r['station_name']); ?></td>
                <td><?php echo str_repeat('⭐', $r['rating']); ?></td>
                <td><?php echo htmlspecialchars(mb_substr($r['comment'] ?? '', 0, 60)) . (mb_strlen($r['comment'] ?? '') > 60 ? '...' : ''); ?></td>
                <td><?php echo $r['is_flagged'] ? '<span class="badge badge-danger">Flagged</span>' : '<span class="badge badge-success">Clean</span>'; ?></td>
                <td><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>