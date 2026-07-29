<?php
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';

// Redirect already-logged-in users to their dashboard
if (Auth::isLoggedIn()) {
    $type = Auth::getCurrentUserType();
    $map = ['driver' => 'dashboard/driver.php', 'owner' => 'dashboard/owner.php', 'admin' => 'dashboard/admin.php'];
    $redirect = $map[$type] ?? 'dashboard/driver.php';
    header('Location: ' . $redirect);
    exit;
}
$project_name = 'WattPulse';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="assets/js/auth.js" defer></script>
    <style>
        body {
            background: linear-gradient(135deg, var(--primary) 0%, #1a1a2e 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .auth-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .auth-header .brand-icon {
            font-size: 40px;
            color: var(--foreground);
            margin-bottom: 12px;
        }
        .auth-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--foreground);
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .auth-header p {
            color: var(--muted-foreground);
            font-size: 14px;
        }
        .user-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 28px;
        }
        .type-option {
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
            background: var(--card);
        }
        .type-option:hover {
            border-color: var(--ring);
        }
        .type-option.active {
            background: var(--primary);
            color: var(--primary-foreground);
            border-color: var(--primary);
        }
        .type-option i {
            font-size: 24px;
            display: block;
            margin-bottom: 8px;
        }
        .type-option p {
            font-size: 12px;
            font-weight: 500;
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
        .progress-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            margin-bottom: 28px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-group input {
            width: 100%;
            padding-right: 40px;
        }
        .input-group .password-toggle {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: var(--muted-foreground);
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .input-group .password-toggle:hover {
            color: var(--foreground);
        }
        .auth-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: var(--primary-foreground);
            border: none;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .auth-btn:hover {
            opacity: 0.9;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .auth-btn.loading {
            opacity: 0.6;
            pointer-events: none;
        }
        .back-btn {
            width: 100%;
            padding: 12px;
            background: var(--muted);
            color: var(--foreground);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .back-btn:hover {
            background: var(--accent);
        }
        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .button-group button {
            flex: 1;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0 16px;
        }
        .divider hr {
            flex: 1;
            border: none;
            border-top: 1px solid var(--border);
        }
        .divider span {
            color: var(--muted-foreground);
            font-size: 13px;
            white-space: nowrap;
        }
        .auth-footer {
            text-align: center;
            font-size: 13px;
            color: var(--muted-foreground);
            margin-top: 24px;
        }
        .auth-footer a {
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        .success-message {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius);
            margin-bottom: 16px;
            font-size: 13px;
            min-height: 20px;
            display: block;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: all 0.3s ease;
            padding: 0;
        }
        .success-message.show {
            max-height: 100px;
            opacity: 1;
            padding: 12px;
        }
        /* ── OTP Modal ── */
        .otp-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }
        .otp-overlay.show { display: flex; }
        .otp-modal {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            max-width: 400px;
            width: 100%;
            padding: 36px 28px 28px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .otp-modal h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--foreground);
            margin: 0 0 4px;
        }
        .otp-modal p {
            font-size: 13px;
            color: var(--muted-foreground);
            margin: 0 0 20px;
        }
        .otp-modal .otp-email {
            font-weight: 600;
            color: var(--foreground);
        }
        .otp-input-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 16px;
        }
        .otp-input-wrap input {
            width: 200px;
            padding: 14px 16px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 10px;
            text-align: center;
            border: 2px solid var(--input);
            border-radius: var(--radius);
            background: var(--card);
            color: var(--foreground);
            font-family: monospace;
            outline: none;
            transition: border-color 0.15s;
        }
        .otp-input-wrap input:focus {
            border-color: var(--ring);
        }
        .otp-error {
            font-size: 13px;
            color: #ef4444;
            min-height: 20px;
            margin-bottom: 12px;
        }
        .otp-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .otp-actions button {
            width: 100%;
            padding: 12px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            border: none;
        }
        .otp-actions .btn-primary {
            background: var(--primary);
            color: var(--primary-foreground);
        }
        .otp-actions .btn-primary:hover { opacity: 0.9; }
        .otp-actions .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .otp-actions .btn-ghost {
            background: transparent;
            color: var(--muted-foreground);
            border: 1px solid var(--border);
        }
        .otp-actions .btn-ghost:hover { background: var(--accent); }
        .otp-actions .btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }
        @media (max-width: 480px) {
            .auth-card { padding: 24px; }
            .user-type-selector { grid-template-columns: 1fr; }
            .button-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="fas fa-plug"></i></div>
            <h1><?php echo htmlspecialchars($project_name); ?></h1>
            <p>Create your account</p>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar">
            <div class="progress-fill" id="progress-fill" style="width: 50%;"></div>
        </div>

        <!-- Messages -->
        <div class="error-message" id="error-message"></div>
        <div class="success-message" id="success-message"></div>

        <!-- User Type Selection (Step 1) -->
        <div class="form-section active" id="step-1">
            <h3 style="margin-bottom: 16px; text-align: center; font-size:15px; color:var(--foreground);">Are you a...</h3>

            <div class="user-type-selector">
                <div class="type-option active" data-type="driver" onclick="selectUserType(this, 'driver')">
                    <i class="fas fa-car"></i>
                    <p>EV Driver</p>
                </div>
                <div class="type-option" data-type="owner" onclick="selectUserType(this, 'owner')">
                    <i class="fas fa-store"></i>
                    <p>Station Owner</p>
                </div>
            </div>

            <button class="auth-btn" onclick="goToStep(2)">Continue</button>
        </div>

        <!-- Registration Form (Step 2) -->
        <div class="form-section" id="step-2">
            <form id="register-form" onsubmit="handleRegister(event)" autocomplete="off">
                <input type="hidden" id="user-type" name="user_type" value="driver">

                <!-- DRIVER FORM -->
                <div id="driver-form">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="driver-name" style="display