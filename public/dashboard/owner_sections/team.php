<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// ponytail: team management is a placeholder — no staff table exists yet
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Team Management</h1>
        <p>Manage your local site staff and operators</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('team')">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="listing-table">
    <div style="text-align:center;padding:48px 0;color:var(--muted-foreground);">
        <i class="fas fa-users" style="font-size:64px;margin-bottom:16px;opacity:0.3;"></i>
        <h3 style="color:var(--foreground);">Team Management</h3>
        <p style="font-size:13px;margin-bottom:16px;">Invite staff members to help manage your stations. This feature is coming soon.</p>
        <button class="btn btn-primary btn-sm" disabled><i class="fas fa-user-plus"></i> Invite Team Member</button>
    </div>
</div>
</write_to_file>