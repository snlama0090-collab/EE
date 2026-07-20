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
                        <label for="driver-name" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Full Name</label>
                        <input type="text" id="driver-name" name="name" placeholder="John Doe" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="driver-email" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Email Address</label>
                        <input type="email" id="driver-email" name="email" placeholder="john@example.com" autocomplete="off" value="" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="driver-phone" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Phone Number</label>
                        <input type="tel" id="driver-phone" name="phone" placeholder="+977 98XXXXXXXX" pattern="(?:\+977\s?)?9[78]\d{8}" title="Enter a valid Nepali phone number (e.g., +977 98XXXXXXXX or 98XXXXXXXX)" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="car-model" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Car Model</label>
                        <input type="text" id="car-model" name="car_model" placeholder="e.g., Tesla Model 3" list="ev-models" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                        <datalist id="ev-models">
                            <option value="Tata Nexon EV"><option value="MG ZS EV"><option value="Hyundai Kona Electric"><option value="BYD Atto 3"><option value="BYD Dolphin"><option value="BYD Seal"><option value="Tata Tigor EV"><option value="Tata Punch EV"><option value="Tata Curvv EV"><option value="Hyundai Ioniq 5"><option value="Hyundai Ioniq 6"><option value="Kia EV6"><option value="Kia EV9"><option value="Nissan Leaf"><option value="Tesla Model 3"><option value="Tesla Model Y"><option value="Tesla Model S"><option value="Mercedes-Benz EQS"><option value="Mercedes-Benz EQB"><option value="BMW iX"><option value="BMW i4"><option value="Volvo XC40 Recharge"><option value="Mahindra XUV400"><option value="MG Comet EV"><option value="Citroën ë-C3"><option value="Porsche Taycan"><option value="Audi e-tron GT">
                        </datalist>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="battery-capacity" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Battery Capacity (kWh)</label>
                        <select id="battery-capacity" name="battery_capacity" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                            <option value="">Select battery capacity</option>
                            <option value="17.3">17.3 kWh (MG Comet)</option>
                            <option value="20">20 kWh</option>
                            <option value="26">26 kWh (Tata Punch EV)</option>
                            <option value="30">30 kWh</option>
                            <option value="40">40 kWh</option>
                            <option value="50">50 kWh</option>
                            <option value="60">60 kWh</option>
                            <option value="70">70 kWh</option>
                            <option value="72">72 kWh (Kia EV6)</option>
                            <option value="77">77 kWh (Hyundai Ioniq 5)</option>
                            <option value="82">82 kWh (Tata Curvv EV)</option>
                            <option value="100">100 kWh</option>
                            <option value="other">Other (custom)</option>
                        </select>
                        <div id="battery-other-wrapper" style="display:none; margin-top:8px;">
                            <input type="number" id="battery-other-input" placeholder="Enter custom capacity (kWh)" step="0.1" min="0" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="preferred-charger" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Preferred Charger Type</label>
                        <select id="preferred-charger" name="charger_preference" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                            <option value="">Select charger type</option>
                            <option value="dc_fast">DC Fast</option>
                            <option value="ac_22kw">AC 22kW</option>
                            <option value="ac_11kw">AC 11kW</option>
                            <option value="ac_7kw">AC 7kW</option>
                        </select>
                    </div>
                </div>

                <!-- OWNER FORM -->
                <div id="owner-form" style="display: none;">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="owner-name" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Your Name</label>
                        <input type="text" id="owner-name" name="name" placeholder="John Doe" style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="company-name" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Company Name</label>
                        <input type="text" id="company-name" name="company_name" placeholder="Green Energy Ltd" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="owner-email" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Email Address</label>
                        <input type="email" id="owner-email" name="email" placeholder="company@example.com" autocomplete="off" value="" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="owner-phone" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Phone Number</label>
                        <input type="tel" id="owner-phone" name="phone" placeholder="+977 98XXXXXXXX" pattern="(?:\+977\s?)?9[78]\d{8}" title="Enter a valid Nepali phone number (e.g., +977 98XXXXXXXX or 98XXXXXXXX)" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="bank-account" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Bank Account Number</label>
                        <input type="text" id="bank-account" name="bank_account" placeholder="Your bank account" required style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="company-description" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Company Description</label>
                        <textarea id="company-description" name="description" placeholder="Tell us about your company..." style="width:100%;padding:10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);min-height:80px;resize:vertical;"></textarea>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label for="password" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Password</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" placeholder="Minimum 8 characters" autocomplete="new-password" value="" required style="width:100%;padding:10px 40px 10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', 'eye-icon-password')">
                            <i class="fas fa-eye" id="eye-icon-password"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label for="confirm-password" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--foreground);">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" id="confirm-password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password" value="" required style="width:100%;padding:10px 40px 10px 12px;border:1px solid var(--input);border-radius:var(--radius);font-size:14px;background:var(--card);color:var(--foreground);">
                        <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm-password', 'eye-icon-confirm')">
                            <i class="fas fa-eye" id="eye-icon-confirm"></i>
                        </button>
                    </div>
                </div>

                <!-- Terms & Conditions -->
                <div class="checkbox-group" style="display:flex;align-items:flex-start;gap:8px;margin-bottom:20px;">
                    <input type="checkbox" id="terms" name="terms" required style="width:auto;margin-top:2px;">
                    <label for="terms" style="margin:0;font-size:13px;">I agree to the <a href="#" target="_blank" style="color:var(--foreground);">Terms & Conditions</a> and <a href="#" target="_blank" style="color:var(--foreground);">Privacy Policy</a></label>
                </div>

                <div class="button-group">
                    <button type="button" class="back-btn" onclick="goToStep(1)">Back</button>
                    <button type="submit" class="auth-btn" id="submit-btn">Create Account</button>
                </div>
            </form>
        </div>

        <!-- Google Sign-In Divider -->
        <div class="divider">
            <hr><span>or register with</span><hr>
        </div>

        <!-- Google Sign-In Button -->
        <div id="google-btn-wrapper" style="display: flex; justify-content: center; margin-bottom: 20px;">
            <div id="g_id_onload"
                 data-client_id="34761081203-1t4na3klvstmlgevj3rq3o9bdagsm2rs.apps.googleusercontent.com"
                 data-callback="handleGoogleRegister"
                 data-auto_prompt="false">
            </div>
            <div class="g_id_signin"
                 data-type="standard"
                 data-size="large"
                 data-theme="outline"
                 data-text="signup_with"
                 data-shape="rectangular"
                 data-logo_alignment="left"
                 data-width="340">
            </div>
        </div>

        <!-- Login Link -->
        <div class="auth-footer">
            Already have an account?
            <a href="login.php">Sign in here</a>
        </div>
    </div>

    <script>
        let selectedUserType = 'driver';

        // Battery capacity "Other" toggle
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === 'battery-capacity') {
                var wrapper = document.getElementById('battery-other-wrapper');
                var otherInput = document.getElementById('battery-other-input');
                if (e.target.value === 'other') {
                    wrapper.style.display = 'block';
                    otherInput.setAttribute('required', '');
                } else {
                    wrapper.style.display = 'none';
                    otherInput.removeAttribute('required');
                }
            }
        });

        function selectUserType(element, type) {
            if (typeof type === 'undefined') {
                type = element;
                element = document.querySelector(`.type-option[data-type="${type}"]`);
            }
            selectedUserType = type;
            document.getElementById('user-type').value = type;
            document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('active'));
            if (element) element.classList.add('active');

            const driverForm = document.getElementById('driver-form');
            const ownerForm = document.getElementById('owner-form');
            if (type === 'driver') {
                driverForm.style.display = 'block';
                ownerForm.style.display = 'none';
                setFormFieldsState(driverForm, true);
                setFormFieldsState(ownerForm, false);
            } else {
                driverForm.style.display = 'none';
                ownerForm.style.display = 'block';
                setFormFieldsState(driverForm, false);
                setFormFieldsState(ownerForm, true);
            }
        }

        function setFormFieldsState(formElement, enabled) {
            formElement.querySelectorAll('input, select, textarea').forEach(f => f.disabled = !enabled);
        }

        function goToStep(step) {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');
            const progress = document.getElementById('progress-fill');
            if (step === 1) {
                step1.classList.add('active');
                step2.classList.remove('active');
                progress.style.width = '50%';
            } else {
                step1.classList.remove('active');
                step2.classList.add('active');
                progress.style.width = '100%';
                selectUserType(selectedUserType);
            }
        }

        async function handleRegister(event) {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);

            if (data.battery_capacity === 'other') {
                const customVal = document.getElementById('battery-other-input').value;
                if (!customVal) { showToast('Please enter a custom battery capacity', 'error'); return; }
                data.battery_capacity = customVal;
            }

            if (data.password !== data.confirm_password) { showToast('Passwords do not match', 'error'); return; }
            if (data.password.length < 8) { showToast('Password must be at least 8 characters', 'error'); return; }
            if (!data.terms) { showToast('Please accept terms & conditions', 'error'); return; }

            const submitBtn = document.getElementById('submit-btn');
            submitBtn.classList.add('loading');
            submitBtn.textContent = 'Creating account...';
            submitBtn.disabled = true;

            try {
                const response = await fetch('/EE/api/auth/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                const result = await response.json();
                if (result.status === 'success') {
                    showToast('Account created successfully! Redirecting to login...', 'success');
                    setTimeout(() => window.location.href = 'login.php?type=' + selectedUserType, 2000);
                } else {
                    showToast(result.message || 'Registration failed', 'error');
                    submitBtn.classList.remove('loading');
                    submitBtn.textContent = 'Create Account';
                    submitBtn.disabled = false;
                }
            } catch (error) {
                showToast('Network error. Please try again.', 'error');
                submitBtn.classList.remove('loading');
                submitBtn.textContent = 'Create Account';
                submitBtn.disabled = false;
            }
        }

        function showError(message) {
            const msg = document.getElementById('error-message');
            msg.textContent = 'Error: ' + message;
            msg.classList.add('show');
        }

        function showSuccess(message) {
            const msg = document.getElementById('success-message');
            msg.textContent = message;
            msg.classList.add('show');
        }

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                const submitBtn = document.getElementById('submit-btn');
                if (submitBtn) { submitBtn.classList.remove('loading'); submitBtn.textContent = 'Create Account'; submitBtn.disabled = false; }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            ['driver-name','driver-email','driver-phone','car-model','battery-capacity','battery-other-input','preferred-charger',
             'owner-name','company-name','owner-email','owner-phone','bank-account','company-description',
             'password','confirm-password'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
        });

        function togglePasswordVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
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

        // Google Register callback
        async function handleGoogleRegister(response) {
            const idToken = response.credential;
            const wrapper = document.getElementById('google-btn-wrapper');
            const originalHTML = wrapper.innerHTML;
            wrapper.innerHTML = '<div style="text-align:center;padding:10px;color:var(--muted-foreground);font-size:14px;"><span class="spinner"></span> Creating your account...</div>';

            try {
                const res = await fetch('/EE/api/auth/google.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: idToken, user_type: selectedUserType })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    wrapper.innerHTML = originalHTML;
                    showToast(data.message || 'Google registration failed.', 'error');
                }
            } catch (err) {
                wrapper.innerHTML = originalHTML;
                showToast('Network error during Google Sign-Up.', 'error');
            }
        }
    </script>
</body>
</html>