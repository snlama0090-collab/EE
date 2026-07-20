<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('driver');
?>
<div class="listing-header">
    <div class="listing-title">
        <h1>Help & Support</h1>
        <p>Get help with your charging experience</p>
    </div>
</div>

<div class="breadcrumb">
    <a href="#" onclick="loadSection('dashboard'); return false;">Dashboard</a>
    <i class="fas fa-chevron-right"></i>
    <span class="current">Support</span>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:20px;">
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-book" style="color:#007AFF;"></i></div>
        <h3 style="margin-bottom:8px;">User Guide</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Learn how to find stations, book chargers, and manage your account.</p>
        <a href="#" style="color:#007AFF;font-size:13px;font-weight:600;">View Guide <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-life-ring" style="color:#007AFF;"></i></div>
        <h3 style="margin-bottom:8px;">Contact Us</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Having trouble? Our support team is here to help.</p>
        <a href="mailto:support@evcharge.com" style="color:#007AFF;font-size:13px;font-weight:600;">Email Support <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card" style="padding:24px;">
        <div style="font-size:32px;margin-bottom:12px;"><i class="fas fa-question-circle" style="color:#007AFF;"></i></div>
        <h3 style="margin-bottom:8px;">FAQ</h3>
        <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Find answers to common questions about charging and billing.</p>
        <a href="#" style="color:#007AFF;font-size:13px;font-weight:600;">Browse FAQ <i class="fas fa-arrow-right"></i></a>
    </div>
</div>
</write_to_file>