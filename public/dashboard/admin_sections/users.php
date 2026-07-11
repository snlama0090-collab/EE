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
<h2 style="margin-bottom: 24px;"><i class="fas fa-users"></i> Users & Owners</h2>

<div class="table-container">
    <table>
        <thead>
            <tr><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo htmlspecialchars($u['name']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><span class="badge badge-info"><?php echo $u['role']; ?></span></td>
                <td><?php echo htmlspecialchars($u['phone'] ?? '-'); ?></td>
                <td><span class="badge badge-<?php echo $u['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo $u['status']; ?></span></td>
                <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>