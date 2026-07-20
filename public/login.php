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
$role_subtitles = ['admin' => 'Admin', 'owner' => 'Station Owner', 'driver' => 'Driver'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        body {
            background: linear-gradient(135deg, var(--primary) 0%, #1a1a2e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .auth-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 420px;
            width: 100%;
            padding: 40px;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 32px;
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
        .auth-header .role-badge {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 12px;
            border-radius: 999px;
            background: var(--muted);
            color: var(--muted-foreground);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .user-type-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 28px;
        }
        .user-type-tabs .tab-btn {
            padding: 10px;
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            color: var(--muted-foreground);
            transition: all 0.15s ease;
        }
        .user-type-tabs .tab-btn:hover {
            border-color: var(--ring);
            color: var(--foreground);
        }
        .user-type-tabs .tab-btn.active {
            background: var(--primary);
            color: var(--primary-foreground);
            border-color: var(--primary);
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
            margin-bottom: 16px;
        }
        .auth-btn:hover {
            opacity: 0.9;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
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
        }
        .auth-footer a {
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
        @media (max-width: 480px) {
            .auth-card { padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="fas fa-plug"></i></div>
        <h1 style="font-size:24px;font-weight:700;letter-spacing:-0.02em;margin-bottom:4px;color:var(--foreground);">Sign In</h1>
        <p style="color:var(--muted-foreground);font-size:14px;">Sign in to your account</p>
            <div class="role-badge" id="role-badge">Multi-Role Access</div>
        </div>

        <!-- User Type Selection -->
        <div class="user-type-tabs">
            <button class="tab-btn active" data-type="driver" onclick="switchUserType('driver')">
                <i class="fas fa-car"></i> Driver
            </button>
            <button class="tab-btn" data-type="owner" onclick="switchUserType('owner')">
                <i class="fas fa-store"></i> Owner
            </button>
            <button class="tab-btn" data-type="admin" onclick="switchUserType('admin')">
                <i class="fas fa-shield-alt"></i> Admin
            </button>
        </div>

        <form id="login-form" onsubmit="handleLogin(event)" autocomplete="off">
            <input type="hidden" id="user-type" name="user_type" value="driver">

            <!-- Error Message -->
            <div class="error-message" id="error-message"></div>

            <!-- Email Field -->
            <div class="form-group" style="margin-bottom:16px;">
                <label for="email" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Email Address</label>
                <input type="email" id="email" name="email" placeholder="your@email.com" autocomplete="off" value="" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
            </div>

            <!-- Password Field -->
            <div class="form-group" style="margin-bottom:16px;">
                <label for="password" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="new-password" value="" required style="width:100%;padding:10px 40px 10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <!-- Remember Me -->
            <div class="checkbox-group">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>

            <!-- Login Button -->
            <button type="submit" class="auth-btn" id="login-btn">
                <span id="btn-text">Sign In</span>
            </button>
        </form>

        <!-- Google Sign-In Divider -->
        <div class="divider">
            <hr><span>or continue with</span><hr>
        </div>

        <!-- Google Sign-In Button -->
        <div id="google-btn-wrapper" style="display: flex; justify-content: center; margin-bottom: 20px;">
            <div id="g_id_onload"
                 data-client_id="34761081203-1t4na3klvstmlgevj3rq3o9bdagsm2rs.apps.googleusercontent.com"
                 data-callback="handleGoogleSignIn"
                 data-auto_prompt="false">
            </div>
            <div class="g_id_signin"
                 data-type="standard"
                 data-size="large"
                 data-theme="outline"
                 data-text="signin_with"
                 data-shape="rectangular"
                 data-logo_alignment="left"
                 data-width="340">
            </div>
        </div>

        <!-- Register Link -->
        <div class="auth-footer">
            Don't have an account?
            <a href="register.php">Create one now</a>
        </div>
    </div>

    <script>
        const form = document.getElementById('login-form');
        const userTypeInput = document.getElementById('user-type');
        const errorMessage = document.getElementById('error-message');
        const tabButtons = document.querySelectorAll('.tab-btn');
        const roleBadge = document.getElementById('role-badge');
        const roleLabels = <?php echo json_encode($role_subtitles); ?>;

        // Get user type from URL parameter if provided
        const urlParams = new URLSearchParams(window.location.search);
        const initialType = urlParams.get('type') || 'driver';
        switchUserType(initialType);

        function switchUserType(type) {
            userTypeInput.value = type;
            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.type === type) {
                    btn.classList.add('active');
                }
            });
            roleBadge.textContent = roleLabels[type] || 'Multi-Role Access';
        }

        async function handleLogin(event) {
            event.preventDefault();

            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const userType = userTypeInput.value;
            const remember = document.getElementById('remember').checked;
            const loginBtn = document.getElementById('login-btn');
            const btnText = document.getElementById('btn-text');

            if (!email || !password) { showToast('Please fill in all fields', 'error'); return; }
            if (password.length < 6) { showToast('Password must be at least 6 characters', 'error'); return; }

            loginBtn.classList.add('loading');
            btnText.innerHTML = '<span class="spinner"></span>Signing in...';
            loginBtn.disabled = true;

            try {
                const response = await fetch('/EE/api/auth/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password, user_type: userType, remember })
                });

                if (!response.ok) { throw new Error('HTTP ' + response.status); }

                const data = await response.json();

                if (data.status === 'success') {
                    const redirectUrl = {
                        'driver': 'dashboard/driver.php',
                        'owner': 'dashboard/owner.php',
                        'admin': 'dashboard/admin.php'
                    }[userType];
                    window.location.href = redirectUrl;
                } else {
                    showToast(data.message || 'Login failed. Please try again.', 'error');
                    loginBtn.classList.remove('loading');
                    btnText.textContent = 'Sign In';
                    loginBtn.disabled = false;
                }
            } catch (error) {
                console.error('Login error:', error);
                showToast('Network error. Please try again.', 'error');
                loginBtn.classList.remove('loading');
                btnText.textContent = 'Sign In';
                loginBtn.disabled = false;
            }
        }

        function showError(message) {
            errorMessage.textContent = 'Error: ' + message;
            errorMessage.classList.add('show');
            errorMessage.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        // Back-button cache bust
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
                const btnText = document.getElementById('btn-text');
                if (btnText) btnText.textContent = 'Sign In';
                const loginBtn = document.getElementById('login-btn');
                if (loginBtn) {
                    loginBtn.classList.remove('loading');
                    loginBtn.disabled = false;
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
        });

        // Google Sign-In callback
        async function handleGoogleSignIn(response) {
            const idToken = response.credential;
            const userType = userTypeInput.value;
            const wrapper = document.getElementById('google-btn-wrapper');
            const originalHTML = wrapper.innerHTML;
            wrapper.innerHTML = '<div style="text-align:center; padding:10px; color:var(--muted-foreground); font-size:14px;"><span class="spinner"></span> Signing in with Google...</div>';

            try {
                const res = await fetch('/EE/api/auth/google.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: idToken, user_type: userType })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    wrapper.innerHTML = originalHTML;
                    showToast(data.message || 'Google Sign-In failed.', 'error');
                    if (window.google && google.accounts) {
                        google.accounts.id.renderButton(
                            document.querySelector('.g_id_signin'),
                            { theme: 'outline', size: 'large', width: 340 }
                        );
                    }
                }
            } catch (err) {
                console.error('Google sign-in error:', err);
                wrapper.innerHTML = originalHTML;
                showToast('Network error during Google Sign-In.', 'error');
            }
        }
    </script>
</body>
</html>