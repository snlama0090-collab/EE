<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('admin');
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Help & Support</h1>
        <p>Platform documentation and support resources</p>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('overview'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Support</span>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:20px;">
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-book" style="color:var(--primary);"></i></div>
        <h3 style="margin-bottom:8px;">Documentation</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Access the platform user guide, API documentation, and admin manual.</p>
        <a href="#" style="color:var(--primary);font-size:13px;font-weight:600;">View Docs <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-life-ring" style="color:var(--primary);"></i></div>
        <h3 style="margin-bottom:8px;">Contact Support</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Reach out to the development team for technical assistance.</p>
        <a href="mailto:support@evcharge.com" style="color:var(--primary);font-size:13px;font-weight:600;">Email Support <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-question-circle" style="color:var(--primary);"></i></div>
        <h3 style="margin-bottom:8px;">FAQ</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Frequently asked questions about platform operations.</p>
        <a href="#" style="color:var(--primary);font-size:13px;font-weight:600;">Browse FAQ <i class="fas fa-arrow-right"></i></a>
    </div>
</div>

<div class="card" style="margin-top:24px;padding:24px;">
    <h3 style="margin-bottom:16px;"><i class="fas fa-info-circle"></i> System Information</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
        <div><strong>Platform Version:</strong> 1.0.0</div>
        <div><strong>Environment:</strong> <?php echo ENV; ?></div>
        <div><strong>Database:</strong> <?php echo DB_NAME; ?></div>
        <div><strong>Timezone:</strong> Asia/Kathmandu</div>
    </div>
</div>
</write_to_file>