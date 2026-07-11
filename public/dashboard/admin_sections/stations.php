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
<h2 style="margin-bottom: 24px;"><i class="fas fa-charging-station"></i> All Stations</h2>

<div class="table-container">
    <table>
        <thead>
            <tr><th>Name</th><th>Owner</th><th>City</th><th>Chargers</th><th>Status</th><th>Approval</th></tr>
        </thead>
        <tbody>
            <?php foreach ($stations as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars($s['name']); ?></td>
                <td><?php echo htmlspecialchars($s['company_name']); ?></td>
                <td><?php echo htmlspecialchars($s['city']); ?></td>
                <td><?php echo $s['num_chargers']; ?></td>
                <td><span class="badge badge-<?php echo $s['is_active'] ? 'success' : 'danger'; ?>"><?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                <td><span class="badge badge-<?php echo $s['approval_status'] === 'approved' ? 'success' : ($s['approval_status'] === 'pending' ? 'warning' : 'danger'); ?>"><?php echo $s['approval_status']; ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>