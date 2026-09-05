<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Placeholder: team/staff management is NOT implemented — there is no staff
// table in the schema (database/schema.sql) and no backend endpoints. Planned
// scope is not tracked anywhere yet; the only reference is the one-line
// "Staff management placeholder" note in PROJECT_REPORT.md. This page renders
// an informational empty state only — deliberately no interactive controls,
// so nothing implies functionality that doesn't exist.
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Team Management</h1>
        <p>Staff account management is planned for a future update</p>
    </div>
    <div class="listing-actions">
        <button type="button" class="btn btn-secondary" onclick="loadSection('team', true)">
            <i class="fas fa-sync"></i> Refresh
        </button>
    </div>
</div>

<div class="listing-table">
    <div style="text-align:center;padding:48px 0;color:var(--muted-foreground);">
        <i class="fas fa-users" style="font-size:64px;margin-bottom:16px;opacity:0.3;"></i>
        <h3 style="color:var(--foreground);">Team management isn't available yet</h3>
        <p style="font-size:13px;">Team management will let you add staff accounts to help manage your stations. This feature isn't available yet.</p>
    </div>
</div>
