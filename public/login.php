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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EV Charging Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            max-width: 420px;
            width: 100%;
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header i {
            font-size: 48px;
            color: #007AFF;
            margin-bottom: 16px;
        }

        .login-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: #1C1C1E;
        }

        .login-header p {
            color: #8E8E93;
            font-size: 14px;
        }

        /* User Type Tabs */
        .user-type-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 32px;
        }

        .tab-btn {
            padding: 10px;
            border: 2px solid #E5E5EA;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .tab-btn:hover {
            border-color: #007AFF;
            color: #007AFF;
        }

        .tab-btn.active {
            background: #007AFF;
            color: white;
            border-color: #007AFF;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 12px;
            background: #007AFF;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 16px;
        }

        .login-btn:hover {
            background: #0051D5;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 122, 255, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        /* Register Link */
        .register-link {
            text-align: center;
            font-size: 13px;
            color: #8E8E93;
        }

        .register-link a {
            color: #007AFF;
            text-decoration: none;
            font-weight: 500;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                padding: 24px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .user-type-tabs {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-plug"></i>
            <h1>EV Charging Station</h1>
            <p>Sign in to your account</p>
        </div>

        <!-- User Type Selection -->
        <div class="user-type-tabs">
            <button class="tab-btn active" data-type="driver" onclick="switchUserType('driver')">
                <i class="fas fa-car"></i> Driver
            </button>
            <button class="tab-btn" data-type="owner" onclick="switchUserType('owner')">
                <i class="fas fa-building"></i> Owner
            </button>
            <button class="tab-btn" data-type="admin" onclick="switchUserType('admin')">
                <i class="fas fa-lock"></i> Admin
            </button>
        </div>

        <form id="login-form" onsubmit="handleLogin(event)" autocomplete="off">
            <input type="hidden" id="user-type" name="user_type" value="driver">

            <!-- Error Message -->
            <div class="error-message" id="error-message"></div>

            <!-- Email Field -->
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="your@email.com" autocomplete="off" value="" required>
            </div>

            <!-- Password Field -->
            <div class="form-group">
                <label for="password">Password</label>
                <div style="position: relative; display: flex; align-items: center;">
                    <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="new-password" value="" required style="width: 100%; padding-right: 40px;">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility()" style="position: absolute; right: 12px; background: none; border: none; cursor: pointer; font-size: 16px; color: #007AFF; padding: 4px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <!-- Remember Me -->
            <div class="checkbox-group">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>

            <!-- ponytail: forgot-password link removed — page doesn't exist yet -->

            <!-- Login Button -->
            <button type="submit" class="login-btn">
                <span id="btn-text">Sign In</span>
            </button>
        </form>

        <!-- Google Sign-In Divider -->
        <div style="display: flex; align-items: center; gap: 12px; margin: 20px 0;">
            <div style="flex: 1; height: 1px; background: #E5E5EA;"></div>
            <span style="color: #8E8E93; font-size: 13px; white-space: nowrap;">or continue with</span>
            <div style="flex: 1; height: 1px; background: #E5E5EA;"></div>
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
        <div class="register-link">
            Don't have an account? 
            <a href="register.php">Create one now</a>
        </div>
    </div>

    <script>
        const form = document.getElementById('login-form');
        const userTypeInput = document.getElementById('user-type');
        const errorMessage = document.getElementById('error-message');
        const tabButtons = document.querySelectorAll('.tab-btn');

        // Get user type from URL parameter if provided
        const urlParams = new URLSearchParams(window.location.search);
        const initialType = urlParams.get('type') || 'driver';
        switchUserType(initialType);

        function switchUserType(type) {
            userTypeInput.value = type;
            
            // Update active button
            tabButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.type === type) {
                    btn.classList.add('active');
                }
            });
        }

        async function handleLogin(event) {
            event.preventDefault();
            
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const userType = userTypeInput.value;
            const remember = document.getElementById('remember').checked;
            
            // Clear previous errors
            errorMessage.classList.remove('show');
            
            // Validate
            if (!email || !password) {
                showError('Please fill in all fields');
                return;
            }
            
            if (password.length < 6) {
                showError('Password must be at least 6 characters');
                return;
            }
            
            // Show loading state
            const loginBtn = event.target.querySelector('.login-btn');
            const btnText = loginBtn.querySelector('#btn-text');
            loginBtn.classList.add('loading');
            btnText.innerHTML = '<span class="spinner"></span>Signing in...';
            loginBtn.disabled = true;
            
            try {
                // API call to login
                const response = await fetch('/EE/api/auth/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        email: email,
                        password: password,
                        user_type: userType,
                        remember: remember
                    })
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Login response error:', response.status, errorText);
                    showError('Login failed. Please try again.');
                    setTimeout(() => {
                        loginBtn.classList.remove('loading');
                        btnText.textContent = 'Sign In';
                        loginBtn.disabled = false;
                    }, 500);
                    return;
                }
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    // Redirect based on user type
                    const redirectUrl = {
                        'driver': 'dashboard/driver.php',
                        'owner': 'dashboard/owner.php',
                        'admin': 'dashboard/admin.php'
                    }[userType];
                    
                    window.location.href = redirectUrl;
                } else {
                    showError(data.message || 'Login failed. Please try again.');
                    setTimeout(() => {
                        loginBtn.classList.remove('loading');
                        btnText.textContent = 'Sign In';
                        loginBtn.disabled = false;
                    }, 500);
                }
            } catch (error) {
                console.error('Login error:', error);
                showError('Network error. Please try again.');
                setTimeout(() => {
                    loginBtn.classList.remove('loading');
                    btnText.textContent = 'Sign In';
                    loginBtn.disabled = false;
                }, 500);
            }
        }

        function showError(message) {
            errorMessage.textContent = '❌ ' + message;
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

        // Back-button cache bust: reset form when navigating back
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
                const btnText = document.getElementById('btn-text');
                if (btnText) btnText.textContent = 'Sign In';
                const loginBtn = document.querySelector('.login-btn');
                if (loginBtn) {
                    loginBtn.classList.remove('loading');
                    loginBtn.disabled = false;
                }
            }
        });

        // Clear form fields on page load (single clean listener)
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
        });

        // ===== GOOGLE SIGN-IN CALLBACK =====
        async function handleGoogleSignIn(response) {
            const idToken = response.credential;
            const userType = userTypeInput.value;

            // Show loading on button area
            const wrapper = document.getElementById('google-btn-wrapper');
            const originalHTML = wrapper.innerHTML;
            wrapper.innerHTML = '<div style="text-align:center; padding: 10px; color: #8E8E93; font-size:14px;"><span class="spinner" style="display:inline-block; width:16px; height:16px; border:2px solid rgba(0,0,0,0.1); border-top-color:#007AFF; border-radius:50%; animation:spin 0.8s linear infinite;"></span> Signing in with Google...</div>';

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
                    showError(data.message || 'Google Sign-In failed. Please try again.');
                    // Re-render Google button
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
                showError('Network error during Google Sign-In. Please try again.');
            }
        }
    </script>
</body>
</html>