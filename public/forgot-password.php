<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

// Already signed in → dashboard (mirrors login.php:7-13)
if (Auth::isLoggedIn()) {
    $type = Auth::getCurrentUserType();
    $map = ['driver' => 'dashboard/driver.php', 'owner' => 'dashboard/owner.php', 'admin' => 'dashboard/admin.php'];
    header('Location: ' . ($map[$type] ?? 'dashboard/driver.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — WattPulse</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="/EE/public/assets/js/csrf.js"></script>
    <style>
        body { background: linear-gradient(135deg, var(--primary) 0%, #1a1a2e 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .auth-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 420px; width: 100%; padding: 40px; }
        .auth-header { text-align: center; margin-bottom: 28px; }
        .auth-header .brand-icon { font-size: 40px; color: var(--foreground); margin-bottom: 12px; }
        .auth-header h1 { font-size: 22px; font-weight: 700; color: var(--foreground); margin-bottom: 4px; }
        .auth-header p { color: var(--muted-foreground); font-size: 14px; }
        .error-message, .success-message { display: none; padding: 10px 12px; border-radius: var(--radius); font-size: 13px; margin-bottom: 14px; }
        .error-message.show { display: block; background: rgba(255,59,48,0.08); color: #FF3B30; }
        .success-message.show { display: block; background: rgba(52,199,89,0.08); color: #34C759; }
        .auth-footer { text-align: center; margin-top: 24px; font-size: 14px; color: var(--muted-foreground); }
        .auth-footer a { color: var(--foreground); font-weight: 600; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="fas fa-key"></i></div>
            <h1>Forgot Password</h1>
            <p>Enter your account email and we'll send a reset link.</p>
        </div>

        <div class="error-message" id="error-message"></div>
        <div class="success-message" id="success-message"></div>

        <form id="forgot-form" novalidate autocomplete="off">
            <div class="form-group" style="margin-bottom:14px;">
                <label for="email" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Email Address</label>
                <input type="email" id="email" name="email" placeholder="yourname@gmail.com" autocomplete="off" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
            </div>
            <div class="form-group" style="margin-bottom:18px;">
                <label for="user-type" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Account Type</label>
                <select id="user-type" name="user_type" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    <option value="driver">EV Driver</option>
                    <option value="owner">Station Owner</option>
                </select>
            </div>
            <button type="submit" class="auth-btn" id="submit-btn" style="width:100%;padding:11px 12px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius);font-size:14px;font-weight:600;cursor:pointer;">Send Reset Link</button>
        </form>

        <div class="auth-footer">
            Remembered it? <a href="login.php">Sign in here</a>
        </div>
    </div>
    <script>
        function showToast(message, type) {
            var msg = document.getElementById(type === 'error' ? 'error-message' : 'success-message');
            msg.textContent = message;
            msg.classList.add('show');
        }

        document.getElementById('forgot-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('submit-btn');
            btn.disabled = true; btn.textContent = 'Sending…';
            fetch('/EE/api/auth/forgot-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('email').value.trim(),
                    user_type: document.getElementById('user-type').value
                })
            }).then(r => r.json()).then(data => {
                // Anti-enumeration: any success response shows the same generic text.
                if (data.status === 'success') {
                    showToast('If that email is registered, a password reset link has been sent.', 'success');
                    document.getElementById('forgot-form').reset();
                } else {
                    showToast(data.message || 'Could not send the reset link right now. Please try again shortly.', 'error');
                }
                btn.disabled = false; btn.textContent = 'Send Reset Link';
            }).catch(() => {
                showToast('Network error. Please try again.', 'error');
                btn.disabled = false; btn.textContent = 'Send Reset Link';
            });
        });
    </script>
</body>
</html>