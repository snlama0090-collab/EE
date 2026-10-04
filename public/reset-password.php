<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Csrf.php';

// Server-side token validation BEFORE rendering the form: an invalid/expired/
// used link never reaches a password field.
$raw = trim($_GET['token'] ?? '');
$tokenValid = false;
if ($raw !== '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM verification_tokens
                          WHERE token = ? AND token_type = 'password_reset'
                            AND is_used = FALSE AND expires_at > NOW()
                          LIMIT 1");
    $stmt->execute([hash('sha256', $raw)]);
    $tokenValid = (bool) $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — WattPulse</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script>window.PW_CONFIG = { min: <?php echo (int) PASSWORD_MIN_LENGTH; ?>, max: <?php echo (int) PASSWORD_MAX_LENGTH; ?> };</script>
    <script src="/EE/public/assets/js/validators.js"></script>
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
        #pw-checklist span { color: #8E8E93; }
        #pw-checklist span.ok { color: #34C759; }
    </style>
</head>
<body>
<?php if (!$tokenValid): ?>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="fas fa-triangle-exclamation"></i></div>
            <h1>Link Invalid or Expired</h1>
            <p>This password reset link is invalid or has expired (links last 30 minutes).</p>
        </div>
        <div class="auth-footer">
            <a href="forgot-password.php">Request a new reset link</a> · <a href="login.php">Sign in</a>
        </div>
    </div>
<?php else: ?>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="fas fa-lock"></i></div>
            <h1>Set a New Password</h1>
            <p>Choose a strong password for your account.</p>
        </div>

        <div class="error-message" id="error-message"></div>
        <div class="success-message" id="success-message"></div>

        <form id="reset-form" novalidate autocomplete="off">
            <div class="form-group" style="margin-bottom:14px;">
                <label for="password" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">New Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" placeholder="8–128 characters" autocomplete="new-password" required style="width:100%;padding:10px 40px 10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    <button type="button" class="password-toggle" onclick="togglePw('password', 'eye-pw')">
                        <i class="fas fa-eye" id="eye-pw"></i>
                    </button>
                </div>
                <div id="pw-checklist" style="margin-top:6px;font-size:12px;"><span id="pw-rule-len"><?php echo (int) PASSWORD_MIN_LENGTH; ?>–<?php echo (int) PASSWORD_MAX_LENGTH; ?> characters</span></div>
            </div>
            <div class="form-group" style="margin-bottom:18px;">
                <label for="confirm-password" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Confirm Password</label>
                <div class="input-group">
                    <input type="password" id="confirm-password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password" required style="width:100%;padding:10px 40px 10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    <button type="button" class="password-toggle" onclick="togglePw('confirm-password', 'eye-cpw')">
                        <i class="fas fa-eye" id="eye-cpw"></i>
                    </button>
                </div>
                <div id="pw-match" style="margin-top:6px;font-size:12px;min-height:15px;"></div>
            </div>
            <button type="submit" class="auth-btn" id="submit-btn" style="width:100%;padding:11px 12px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius);font-size:14px;font-weight:600;cursor:pointer;">Reset Password</button>
        </form>

        <div class="auth-footer">
            <a href="login.php">Back to sign in</a>
        </div>
    </div>
    <script>
        var pwInput = document.getElementById('password');
        var confirmInput = document.getElementById('confirm-password');
        var ruleLen = document.getElementById('pw-rule-len');
        var matchEl = document.getElementById('pw-match');

        function togglePw(inputId, iconId) {
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Length rule + live match indicator; both update as either field changes.
        function repaint() {
            var lenOk = window.AuthValidators.isPasswordValidLength(pwInput.value);
            ruleLen.classList.toggle('ok', lenOk);
            if (confirmInput.value === '') {
                matchEl.textContent = '';
            } else {
                var same = pwInput.value === confirmInput.value;
                matchEl.textContent = same ? 'Passwords match' : 'Passwords do not match';
                matchEl.style.color = same ? '#34C759' : '#FF3B30';
            }
        }
        pwInput.addEventListener('input', repaint);
        confirmInput.addEventListener('input', repaint);

        function showToast(message, type) {
            var msg = document.getElementById(type === 'error' ? 'error-message' : 'success-message');
            msg.textContent = message;
            msg.classList.add('show');
        }

        document.getElementById('reset-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var pw = pwInput.value, confirmPw = confirmInput.value;
            var AV = window.AuthValidators;
            if (!AV.isPasswordValidLength(pw)) { showToast('Password must be between ' + AV.PW_MIN + ' and ' + AV.PW_MAX + ' characters.', 'error'); return; }
            if (pw !== confirmPw) { showToast('Passwords do not match.', 'error'); return; }

            var token = new URLSearchParams(location.search).get('token') || '';
            var btn = document.getElementById('submit-btn');
            btn.disabled = true; btn.textContent = 'Resetting…';
            fetch('/EE/api/auth/reset-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token, password: pw })
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') {
                    showToast('Password updated. Redirecting you to sign in…', 'success');
                    setTimeout(function () { window.location.href = 'login.php'; }, 1800);
                } else {
                    showToast(data.message || 'Could not reset the password. Please request a new link.', 'error');
                    btn.disabled = false; btn.textContent = 'Reset Password';
                }
            }).catch(() => {
                showToast('Network error. Please try again.', 'error');
                btn.disabled = false; btn.textContent = 'Reset Password';
            });
        });
    </script>
</body>
</html>
<?php endif; ?>