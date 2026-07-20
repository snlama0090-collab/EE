<?php
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';

// Redirect authenticated users to their dashboard
if (Auth::isLoggedIn() && Auth::isSessionValid()) {
    $role = Auth::getCurrentUserType();
    $dashboard = ['admin' => 'dashboard/admin.php', 'owner' => 'dashboard/owner.php', 'driver' => 'dashboard/driver.php'];
    if (isset($dashboard[$role])) {
        header('Location: ' . $dashboard[$role]);
        exit;
    }
}

$project_name = 'WattPulse';
$user_role = Auth::isLoggedIn() ? Auth::getCurrentUserType() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> — EV Charging Network</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body { background: var(--background); color: var(--foreground); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .landing-header { position: fixed; top: 0; left: 0; right: 0; height: var(--header-height); background: var(--card); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; padding: 0 24px; z-index: 100; }
        .landing-header .brand { display: flex; flex-direction: column; line-height: 1.2; }
        .landing-header .brand .brand-name { font-size: 16px; font-weight: 700; color: var(--foreground); letter-spacing: -0.02em; }
        .landing-header .brand .brand-sub { font-size: 9px; font-weight: 600; color: var(--muted-foreground); text-transform: uppercase; letter-spacing: 0.15em; }
        .landing-nav { display: flex; align-items: center; gap: 8px; }
        .hero-section { margin-top: var(--header-height); display: flex; align-items: center; gap: 40px; padding: 60px 24px; max-width: 1100px; margin-left: auto; margin-right: auto; min-height: calc(100vh - var(--header-height) - 200px); }
        .hero-content { flex: 1; }
        .hero-content h1 { font-size: 40px; font-weight: 700; letter-spacing: -0.03em; margin-bottom: 12px; line-height: 1.15; }
        .hero-content p { font-size: 16px; color: var(--muted-foreground); margin-bottom: 24px; max-width: 480px; line-height: 1.6; }
        .hero-stats { display: flex; gap: 32px; margin-top: 32px; }
        .hero-stats .stat h3 { font-size: 28px; font-weight: 700; color: var(--foreground); }
        .hero-stats .stat p { font-size: 13px; color: var(--muted-foreground); margin: 0; }
        .hero-visual { flex: 1; min-height: 300px; border-radius: var(--radius); border: 1px solid var(--border); background: var(--muted); display: flex; align-items: center; justify-content: center; color: var(--muted-foreground); }
        .hero-visual i { font-size: 64px; opacity: 0.3; }
        .section-title { text-align: center; font-size: 28px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 8px; }
        .section-desc { text-align: center; font-size: 14px; color: var(--muted-foreground); margin-bottom: 32px; }
        .role-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; max-width: 1100px; margin: 0 auto 48px; padding: 0 24px; }
        .role-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 32px 24px; text-align: center; transition: all 0.15s ease; text-decoration: none; display: block; }
        .role-card:hover { border-color: var(--ring); box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .role-card i { font-size: 32px; color: var(--foreground); margin-bottom: 16px; }
        .role-card h3 { font-size: 16px; font-weight: 600; margin-bottom: 8px; color: var(--foreground); }
        .role-card p { font-size: 13px; color: var(--muted-foreground); line-height: 1.5; }
        .role-card .btn { margin-top: 20px; }
        .features-section { padding: 60px 24px; max-width: 1100px; margin: 0 auto; }
        .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .feature-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; text-align: center; transition: all 0.15s ease; }
        .feature-card:hover { border-color: var(--ring); }
        .feature-card i { font-size: 24px; color: var(--foreground); margin-bottom: 12px; }
        .feature-card h4 { font-size: 14px; font-weight: 600; margin-bottom: 6px; color: var(--foreground); }
        .feature-card p { font-size: 12px; color: var(--muted-foreground); line-height: 1.5; }
        .steps-section { padding: 60px 24px; max-width: 1100px; margin: 0 auto; }
        .steps-grid { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
        .step-item { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; text-align: center; flex: 1; min-width: 140px; max-width: 180px; }
        .step-number { width: 36px; height: 36px; border-radius: 50%; background: var(--primary); color: var(--primary-foreground); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; margin: 0 auto 12px; }
        .step-item h4 { font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--foreground); }
        .step-item p { font-size: 11px; color: var(--muted-foreground); }
        .cta-section { padding: 60px 24px; text-align: center; }
        .cta-section h2 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 8px; }
        .cta-section p { color: var(--muted-foreground); font-size: 14px; margin-bottom: 24px; }
        .landing-footer { border-top: 1px solid var(--border); background: var(--card); padding: 40px 24px 24px; }
        .footer-inner { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
        .footer-col h5 { font-size: 13px; font-weight: 600; margin-bottom: 12px; color: var(--foreground); }
        .footer-col a, .footer-col p { font-size: 12px; color: var(--muted-foreground); text-decoration: none; display: block; margin-bottom: 6px; }
        .footer-col a:hover { color: var(--foreground); }
        .footer-bottom { max-width: 1100px; margin: 24px auto 0; padding-top: 16px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--muted-foreground); }
        .footer-bottom .social-links { display: flex; gap: 12px; }
        .footer-bottom .social-links a { color: var(--muted-foreground); }
        .footer-bottom .social-links a:hover { color: var(--foreground); }
        .tab-buttons-landing { display: flex; gap: 8px; justify-content: center; margin-bottom: 24px; }
        .tab-btn-landing { padding: 8px 20px; border: 1px solid var(--border); border-radius: 999px; background: var(--card); color: var(--muted-foreground); font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s ease; }
        .tab-btn-landing:hover { border-color: var(--ring); color: var(--foreground); }
        .tab-btn-landing.active { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }
        .map-container { border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; margin-top: 20px; }
        @media (max-width: 768px) {
            .hero-section { flex-direction: column; padding: 40px 16px; text-align: center; }
            .hero-content p { max-width: 100%; }
            .hero-stats { justify-content: center; }
            .role-cards { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr 1fr; }
            .footer-inner { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .hero-content h1 { font-size: 28px; }
            .features-grid { grid-template-columns: 1fr; }
            .footer-inner { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- OPTION 2 TOPBAR -->
    <header class="landing-header">
        <div class="brand">
            <span class="brand-name"><?php echo htmlspecialchars($project_name); ?></span>
            <span class="brand-sub">EV Charging Network</span>
        </div>
        <div class="landing-nav">
            <a href="login.php" class="btn btn-sm btn-secondary">Login</a>
            <a href="register.php" class="btn btn-sm btn-primary">Register</a>
        </div>
    </header>

    <!-- HERO SECTION -->
    <section class="hero-section">
        <div class="hero-content">
            <h1>Find EV Charging Stations Near You ⚡</h1>
            <p>Real-time availability, instant booking, and easy payment in one platform — serving drivers, station owners, and platform operators.</p>
            <a href="login.php?type=driver" class="btn btn-primary" style="padding:10px 24px;font-size:14px;"><i class="fas fa-car"></i> Book a Charger</a>
            <a href="login.php?type=owner" class="btn btn-secondary" style="padding:10px 24px;font-size:14px;margin-left:8px;"><i class="fas fa-store"></i> Host a Station</a>
            <div class="hero-stats">
                <div class="stat"><h3>324+</h3><p>Active Stations</p></div>
                <div class="stat"><h3>1,234</h3><p>EV Drivers</p></div>
                <div class="stat"><h3>87</h3><p>Station Owners</p></div>
            </div>
        </div>
        <div class="hero-visual">
            <i class="fas fa-map"></i>
        </div>
    </section>

    <!-- 3 ROLE CARDS -->
    <h2 class="section-title">Choose Your Path</h2>
    <p class="section-desc">Three portals, one platform — tailored for each role</p>
    <div class="role-cards">
        <a href="login.php?type=driver" class="role-card">
            <i class="fas fa-car"></i>
            <h3>🚗 Driver Hub</h3>
            <p>Find stations, book chargers, track sessions, and manage payments — all from your personal dashboard.</p>
            <span class="btn btn-sm btn-primary">Driver Login / Sign Up</span>
        </a>
        <a href="login.php?type=owner" class="role-card">
            <i class="fas fa-store"></i>
            <h3>🔌 Station Owner Portal</h3>
            <p>Host chargers, set pricing, monitor usage in real time, and view payout statements.</p>
            <span class="btn btn-sm btn-primary">Host a Station</span>
        </a>
        <a href="login.php?type=admin" class="role-card">
            <i class="fas fa-shield-alt"></i>
            <h3>👑 Admin Access</h3>
            <p>Platform governance, network-wide analytics, user management, and moderation tools.</p>
            <span class="btn btn-sm btn-secondary">Admin Portal</span>
        </a>
    </div>

    <!-- FEATURES -->
    <section class="features-section">
        <h2 class="section-title">Why WattPulse?</h2>
        <p class="section-desc">Built for the EV ecosystem — from booking to billing</p>
        <div class="features-grid">
            <div class="feature-card"><i class="fas fa-map-marker-alt"></i><h4>Real-Time Location</h4><p>Find chargers within your desired radius instantly using GPS</p></div>
            <div class="feature-card"><i class="fas fa-clock"></i><h4>Quick Booking</h4><p>Reserve a charger in seconds with instant confirmation</p></div>
            <div class="feature-card"><i class="fas fa-calculator"></i><h4>Smart Calculation</h4><p>Automatic charge time based on your vehicle's battery</p></div>
            <div class="feature-card"><i class="fas fa-credit-card"></i><h4>Easy Payment</h4><p>Secure prepaid billing with flexible options</p></div>
            <div class="feature-card"><i class="fas fa-star"></i><h4>Ratings & Reviews</h4><p>Community-driven quality assurance for all stations</p></div>
            <div class="feature-card"><i class="fas fa-chart-line"></i><h4>Analytics</h4><p>Track usage, revenue, and insights for station owners</p></div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="steps-section">
        <h2 class="section-title">How It Works</h2>
        <p class="section-desc">Two simple flows — one for drivers, one for owners</p>
        <div class="tab-buttons-landing">
            <button class="tab-btn-landing active" onclick="document.querySelectorAll('.tab-btn-landing').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('driver-steps').style.display='flex';document.getElementById('owner-steps').style.display='none';">For Drivers</button>
            <button class="tab-btn-landing" onclick="document.querySelectorAll('.tab-btn-landing').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('driver-steps').style.display='none';document.getElementById('owner-steps').style.display='flex';">For Owners</button>
        </div>
        <div class="steps-grid" id="driver-steps">
            <div class="step-item"><div class="step-number">1</div><h4>Register</h4><p>Create account & set your car details</p></div>
            <div class="step-item"><div class="step-number">2</div><h4>Find</h4><p>Use GPS to find nearby chargers</p></div>
            <div class="step-item"><div class="step-number">3</div><h4>Book</h4><p>Reserve a charger and pay</p></div>
            <div class="step-item"><div class="step-number">4</div><h4>Charge</h4><p>Drive to station and charge</p></div>
            <div class="step-item"><div class="step-number">5</div><h4>Rate</h4><p>Share your experience</p></div>
        </div>
        <div class="steps-grid" id="owner-steps" style="display:none;">
            <div class="step-item"><div class="step-number">1</div><h4>Register</h4><p>Create owner account</p></div>
            <div class="step-item"><div class="step-number">2</div><h4>Add Station</h4><p>Register with charger details</p></div>
            <div class="step-item"><div class="step-number">3</div><h4>Approve</h4><p>Admin approves listing</p></div>
            <div class="step-item"><div class="step-number">4</div><h4>Monitor</h4><p>Track bookings & status</p></div>
            <div class="step-item"><div class="step-number">5</div><h4>Earn</h4><p>Receive revenue per session</p></div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta-section">
        <h2>Ready to Get Started? 🚀</h2>
        <p>Join thousands of EV owners and charging station operators</p>
        <a href="register.php?type=driver" class="btn btn-primary" style="padding:10px 24px;font-size:14px;"><i class="fas fa-user"></i> I'm a Driver</a>
        <a href="register.php?type=owner" class="btn btn-secondary" style="padding:10px 24px;font-size:14px;margin-left:8px;"><i class="fas fa-store"></i> I'm an Owner</a>
    </section>

    <!-- FOOTER -->
    <footer class="landing-footer">
        <div class="footer-inner">
            <div class="footer-col"><h5>WattPulse</h5><p>Making EV charging accessible & affordable for everyone</p></div>
            <div class="footer-col"><h5>Quick Links</h5><a href="#">Features</a><a href="#">How It Works</a><a href="#">About</a></div>
            <div class="footer-col"><h5>For Users</h5><a href="login.php">Login</a><a href="register.php?type=driver">Sign Up</a></div>
            <div class="footer-col"><h5>For Owners</h5><a href="login.php">Owner Login</a><a href="register.php?type=owner">Add Station</a></div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 WattPulse. All rights reserved.</p>
            <div class="social-links">
                <a href="#"><i class="fab fa-facebook"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="assets/js/landing.js"></script>
</body>
</html>