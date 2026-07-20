<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Help & Support</h1>
        <p>Resources and assistance for station owners</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:20px;">
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-book" style="color:#34C759;"></i></div>
        <h3 style="margin-bottom:8px;">Owner Guide</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Learn how to manage stations, handle bookings, and view payouts.</p>
        <a href="#" style="color:#34C759;font-size:13px;font-weight:600;">View Guide <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-life-ring" style="color:#34C759;"></i></div>
        <h3 style="margin-bottom:8px;">Contact Support</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Get help with station operations, payments, or technical issues.</p>
        <a href="mailto:owner-support@evcharge.com" style="color:#34C759;font-size:13px;font-weight:600;">Email Support <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-question-circle" style="color:#34C759;"></i></div>
        <h3 style="margin-bottom:8px;">FAQ</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Answers to common questions about commissions, payouts, and more.</p>
        <a href="#" style="color:#34C759;font-size:13px;font-weight:600;">Browse FAQ <i class="fas fa-arrow-right"></i></a>
    </div>
</div>
</write_to_file>