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
    <title>Register - EV Charging Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        body {
            align-items: flex-start;
            padding: 20px;
        }

        .register-container {
            max-width: 500px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            padding: 40px;
        }

        .register-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .register-header i {
            font-size: 48px;
            color: #007AFF;
            margin-bottom: 16px;
        }

        .register-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: #1C1C1E;
        }

        .register-header p {
            color: #8E8E93;
            font-size: 14px;
        }

        /* User Type Selection */
        .user-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 32px;
        }

        .type-option {
            padding: 16px;
            border: 2px solid #E5E5EA;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }

        .type-option:hover {
            border-color: #007AFF;
            background: rgba(0, 122, 255, 0.05);
        }

        .type-option.active {
            background: #007AFF;
            color: white;
            border-color: #007AFF;
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

        /* Hidden sections */
        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        .checkbox-group {
            align-items: flex-start;
        }

        .checkbox-group input[type="checkbox"] {
            margin-top: 2px;
            cursor: pointer;
        }

        .checkbox-group label {
            flex: 1;
        }

        .checkbox-group a {
            color: #007AFF;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .register-btn {
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
        }

        .register-btn:hover {
            background: #0051D5;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 122, 255, 0.3);
        }

        .register-btn.loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .back-btn {
            width: 100%;
            padding: 12px;
            background: #F2F2F7;
            color: #1C1C1E;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #E5E5EA;
        }

        /* Success Message */
        .success-message {
            background: #34C759;
            color: white;
            border-radius: 8px;
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

        /* Progress Bar */
        .progress-bar {
            height: 4px;
            background: #E5E5EA;
            border-radius: 2px;
            margin-bottom: 32px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #007AFF;
            transition: width 0.3s ease;
        }

        /* Login Link */
        .login-link {
            text-align: center;
            font-size: 13px;
            color: #8E8E93;
            margin-top: 24px;
        }

        .login-link a {
            color: #007AFF;
            text-decoration: none;
            font-weight: 500;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .register-container {
                padding: 24px;
            }

            .register-header h1 {
                font-size: 24px;
            }

            .user-type-selector {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <i class="fas fa-plug"></i>
            <h1>Join Us</h1>
            <p>Create your EV Charging Station account</p>
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
            <h3 style="margin-bottom: 16px; text-align: center;">Are you a...</h3>
            
            <div class="user-type-selector">
                <div class="type-option active" data-type="driver" onclick="selectUserType(this, 'driver')">
                    <i class="fas fa-car"></i>
                    <p>EV Driver</p>
                </div>
                <div class="type-option" data-type="owner" onclick="selectUserType(this, 'owner')">
                    <i class="fas fa-building"></i>
                    <p>Station Owner</p>
                </div>
            </div>

            <button class="register-btn" onclick="goToStep(2)">Continue</button>
        </div>

        <!-- Registration Form (Step 2) -->
        <div class="form-section" id="step-2">
            <form id="register-form" onsubmit="handleRegister(event)" autocomplete="off">
                <input type="hidden" id="user-type" name="user_type" value="driver">

                <!-- DRIVER FORM -->
                <div id="driver-form">
                    <div class="form-group">
                        <label for="driver-name">Full Name</label>
                        <input type="text" id="driver-name" name="name" placeholder="John Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="driver-email">Email Address</label>
                        <input type="email" id="driver-email" name="email" placeholder="john@example.com" autocomplete="off" value="" required>
                    </div>

                    <div class="form-group">
                        <label for="driver-phone">Phone Number</label>
                        <input type="tel" id="driver-phone" name="phone" placeholder="+977 98XXXXXXXX" required>
                    </div>

                    <div class="form-group">
                        <label for="car-model">Car Model</label>
                        <input type="text" id="car-model" name="car_model" placeholder="e.g., Tesla Model 3" required>
                    </div>

                    <div class="form-group">
                        <label for="battery-capacity">Battery Capacity (kWh)</label>
                        <input type="number" id="battery-capacity" name="battery_capacity" placeholder="75" step="0.1" required>
                    </div>

                    <div class="form-group">
                        <label for="preferred-charger">Preferred Charger Type</label>
                        <select id="preferred-charger" name="charger_preference">
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
                    <div class="form-group">
                        <label for="owner-name">Your Name</label>
                        <input type="text" id="owner-name" name="name" placeholder="John Doe">
                    </div>

                    <div class="form-group">
                        <label for="company-name">Company Name</label>
                        <input type="text" id="company-name" name="company_name" placeholder="Green Energy Ltd" required>
                    </div>

                    <div class="form-group">
                        <label for="owner-email">Email Address</label>
                        <input type="email" id="owner-email" name="email" placeholder="company@example.com" autocomplete="off" value="" required>
                    </div>

                    <div class="form-group">
                        <label for="owner-phone">Phone Number</label>
                        <input type="tel" id="owner-phone" name="phone" placeholder="+977 98XXXXXXXX" required>
                    </div>

                    <div class="form-group">
                        <label for="bank-account">Bank Account Number</label>
                        <input type="text" id="bank-account" name="bank_account" placeholder="Your bank account" required>
                    </div>

                    <div class="form-group">
                        <label for="company-description">Company Description</label>
                        <textarea id="company-description" name="description" placeholder="Tell us about your company..."></textarea>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Minimum 8 characters" autocomplete="new-password" value="" required>
                </div>

                <div class="form-group">
                    <label for="confirm-password">Confirm Password</label>
                    <input type="password" id="confirm-password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password" value="" required>
                </div>

                <!-- Terms & Conditions -->
                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">I agree to the <a href="#" target="_blank">Terms & Conditions</a> and <a href="#" target="_blank">Privacy Policy</a></label>
                </div>

                <div class="button-group">
                    <button type="button" class="back-btn" onclick="goToStep(1)">Back</button>
                    <button type="submit" class="register-btn" id="submit-btn">Create Account</button>
                </div>
            </form>
        </div>

        <!-- Google Sign-In Divider -->
        <div style="display: flex; align-items: center; gap: 12px; margin: 24px 0 16px;">
            <div style="flex: 1; height: 1px; background: #E5E5EA;"></div>
            <span style="color: #8E8E93; font-size: 13px; white-space: nowrap;">or register with</span>
            <div style="flex: 1; height: 1px; background: #E5E5EA;"></div>
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
        <div class="login-link">
            Already have an account? 
            <a href="login.php">Sign in here</a>
        </div>
    </div>

    <script>
        let selectedUserType = 'driver';

        function selectUserType(element, type) {
            if (typeof type === 'undefined') {
                type = element;
                element = document.querySelector(`.type-option[data-type="${type}"]`);
            }

            selectedUserType = type;
            document.getElementById('user-type').value = type;
            
            // Update UI
            document.querySelectorAll('.type-option').forEach(opt => {
                opt.classList.remove('active');
            });
            if (element) {
                element.classList.add('active');
            }
            
            // Show corresponding form
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
            const fields = formElement.querySelectorAll('input, select, textarea');
            fields.forEach(field => {
                field.disabled = !enabled;
            });
        }

        function goToStep(step) {
            const step1 = document.getElementById('step-1');
            const step2 = document.getElementById('step-2');
            const progress = document.getElementById('progress-fill');
            
            if (step === 1) {
                step1.classList.add('active');
                step2.classList.remove('active');
                progress.style.width = '50%';
            } else if (step === 2) {
                step1.classList.remove('active');
                step2.classList.add('active');
                progress.style.width = '100%';
                
                // Show correct form
                selectUserType(selectedUserType);
            }
        }

        async function handleRegister(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            
            const errorMsg = document.getElementById('error-message');
            const successMsg = document.getElementById('success-message');
            
            // Clear messages
            errorMsg.classList.remove('show');
            successMsg.classList.remove('show');
            
            // Validation
            if (data.password !== data.confirm_password) {
                showError('Passwords do not match');
                return;
            }
            
            if (data.password.length < 8) {
                showError('Password must be at least 8 characters');
                return;
            }
            
            if (!data.terms) {
                showError('Please accept terms & conditions');
                return;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submit-btn');
            submitBtn.classList.add('loading');
            submitBtn.textContent = 'Creating account...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch('/EE/api/auth/register.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                if (!response.ok) {
                    const responseText = await response.text();
                    showError('Registration failed (HTTP ' + response.status + '): ' + responseText);
                    setTimeout(() => {
                        submitBtn.classList.remove('loading');
                        submitBtn.textContent = 'Create Account';
                        submitBtn.disabled = false;
                    }, 500);
                    return;
                }
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    showSuccess('Account created successfully! Redirecting to login...');
                    setTimeout(() => {
                        window.location.href = 'login.php?type=' + selectedUserType;
                    }, 2000);
                } else {
                    showError(result.message || 'Registration failed');
                    setTimeout(() => {
                        submitBtn.classList.remove('loading');
                        submitBtn.textContent = 'Create Account';
                        submitBtn.disabled = false;
                    }, 500);
                }
            } catch (error) {
                console.error('Registration error:', error);
                showError('Error: ' + error.message);
                setTimeout(() => {
                    submitBtn.classList.remove('loading');
                    submitBtn.textContent = 'Create Account';
                    submitBtn.disabled = false;
                }, 500);
            }
        }

        function showError(message) {
            const msg = document.getElementById('error-message');
            msg.textContent = '❌ ' + message;
            msg.classList.add('show');
        }

        function showSuccess(message) {
            const msg = document.getElementById('success-message');
            msg.textContent = '✅ ' + message;
            msg.classList.add('show');
        }

        // Back-button cache bust: reset form when navigating back
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                const submitBtn = document.getElementById('submit-btn');
                if (submitBtn) {
                    submitBtn.classList.remove('loading');
                    submitBtn.textContent = 'Create Account';
                    submitBtn.disabled = false;
                }
            }
        });

        // Clear form fields on page load (single clean listener)
        document.addEventListener('DOMContentLoaded', function() {
            ['driver-name','driver-email','driver-phone','car-model','battery-capacity','preferred-charger',
             'owner-name','company-name','owner-email','owner-phone','bank-account','company-description',
             'password','confirm-password'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
        });

        // ===== GOOGLE REGISTER CALLBACK =====
        async function handleGoogleRegister(response) {
            const idToken = response.credential;

            // Show loading on button area
            const wrapper = document.getElementById('google-btn-wrapper');
            const originalHTML = wrapper.innerHTML;
            wrapper.innerHTML = '<div style="text-align:center; padding: 10px; color: #8E8E93; font-size:14px;"><span style="display:inline-block; width:16px; height:16px; border:2px solid rgba(0,0,0,0.1); border-top-color:#007AFF; border-radius:50%; animation:spin 0.8s linear infinite; vertical-align:middle;"></span>&nbsp;Creating your account...</div>';

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
                    alert(data.message || 'Google registration failed. Please try again.');
                }
            } catch (err) {
                console.error('Google register error:', err);
                wrapper.innerHTML = originalHTML;
                alert('Network error during Google Sign-Up. Please try again.');
            }
        }
    </script>
</body>
</html>