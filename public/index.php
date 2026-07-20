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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WattPulse — EV Charging Network</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body>
    <!-- ===== NAVBAR ===== -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-logo">
                <i class="fas fa-plug"></i> <?php echo htmlspecialchars($project_name); ?> <span style="font-weight:400;font-size:12px;color:var(--muted-foreground, #8E8E93);margin-left:4px;">EV Charging Network</span>
            </div>
            <div class="nav-menu">
                <a href="#features" class="nav-link">Features</a>
                <a href="#how-it-works" class="nav-link">How It Works</a>
                <a href="#about" class="nav-link">About</a>
                <a href="login.php" class="nav-link login-btn">Login</a>
            </div>
            <div class="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </nav>

    <!-- ===== HERO SECTION ===== -->
    <section class="hero">
        <div class="hero-content">
            <h1>Find EV Charging Stations Near You ⚡</h1>
            <p>Real-time availability, instant booking, and easy payment in one platform</p>

            <div class="cta-buttons" style="margin-top:28px;">
                <a href="login.php?type=driver" class="btn btn-primary btn-large"><i class="fas fa-car"></i> Book a Charger</a>
                <a href="login.php?type=owner" class="btn btn-secondary btn-large"><i class="fas fa-store"></i> Add Your Station</a>
            </div>

            <div class="hero-stats">
                <div class="stat">
                    <h3>324+</h3>
                    <p>Active Stations</p>
                </div>
                <div class="stat">
                    <h3>1,234</h3>
                    <p>EV Drivers</p>
                </div>
                <div class="stat">
                    <h3>87</h3>
                    <p>Station Owners</p>
                </div>
            </div>
        </div>

        <div class="hero-image">
            <div class="map-placeholder">
                <i class="fas fa-map"></i>
            </div>
        </div>
    </section>

    <!-- ===== ROLE ENTRY PATHS ===== -->
    <section class="features" style="padding:48px 0;">
        <div class="container">
            <h2 style="margin-bottom:8px;">Choose Your Path</h2>
            <p style="color:var(--muted-foreground, #8E8E93);text-align:center;font-size:14px;margin-bottom:32px;">Three portals, one platform — tailored for each role</p>
            <div class="features-grid" style="max-width:900px;margin:0 auto;">
                <a href="login.php?type=driver" class="feature-card" style="text-decoration:none;display:block;">
                    <i class="fas fa-car"></i>
                    <h3>🚗 Driver Hub</h3>
                    <p>Find stations, book chargers, track sessions, and manage payments</p>
                </a>
                <a href="login.php?type=owner" class="feature-card" style="text-decoration:none;display:block;">
                    <i class="fas fa-store"></i>
                    <h3>🔌 Station Owner Portal</h3>
                    <p>Host chargers, monitor usage, view payouts, and manage staff</p>
                </a>
                <a href="login.php?type=admin" class="feature-card" style="text-decoration:none;display:block;">
                    <i class="fas fa-shield-alt"></i>
                    <h3>👑 Admin Access</h3>
                    <p>Platform governance, network metrics, user management</p>
                </a>
            </div>
        </div>
    </section>

    <!-- ===== NEARBY STATIONS PREVIEW ===== -->
    <section class="stations-preview">
        <div class="container">
            <h2>Nearby Charging Stations 🔍</h2>
            <p>See available stations in your area (enable location to see real data)</p>

            <div class="location-controls">
                <button id="get-location-btn" class="btn btn-info">
                    <i class="fas fa-location-dot"></i> Use My Location
                </button>
                <span id="location-status">Location not detected</span>
            </div>

            <div id="stations-list" class="stations-grid">
                <div class="station-card loading">
                    <div class="skeleton"></div>
                    <div class="skeleton"></div>
                </div>
                <div class="station-card loading">
                    <div class="skeleton"></div>
                    <div class="skeleton"></div>
                </div>
                <div class="station-card loading">
                    <div class="skeleton"></div>
                    <div class="skeleton"></div>
                </div>
            </div>

            <div class="map-container">
                <div id="map"></div>
            </div>
        </div>
    </section>

    <!-- ===== FEATURES SECTION ===== -->
    <section id="features" class="features">
        <div class="container">
            <h2>Why Choose WattPulse?</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <i class="fas fa-location-arrow"></i>
                    <h3>Real-Time Location</h3>
                    <p>Find chargers within your desired radius instantly</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-clock"></i>
                    <h3>Quick Booking</h3>
                    <p>Reserve a charger in seconds with instant confirmation</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-calculator"></i>
                    <h3>Smart Calculation</h3>
                    <p>Automatic charge time calculation based on your car</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-credit-card"></i>
                    <h3>Easy Payment</h3>
                    <p>Secure payments with flexible billing options</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-star"></i>
                    <h3>Ratings & Reviews</h3>
                    <p>Community-driven quality assurance for all stations</p>
                </div>
                <div class="feature-card">
                    <i class="fas fa-chart-line"></i>
                    <h3>Analytics</h3>
                    <p>Track usage, revenue, and insights for owners</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== HOW IT WORKS ===== -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <h2>How It Works</h2>

            <div class="tabs">
                <div class="tab-buttons">
                    <button class="tab-btn active" data-tab="driver">
                        <i class="fas fa-car"></i> For Drivers
                    </button>
                    <button class="tab-btn" data-tab="owner">
                        <i class="fas fa-building"></i> For Owners
                    </button>
                </div>

                <div class="tab-content active" id="driver-tab">
                    <div class="steps">
                        <div class="step">
                            <div class="step-number">1</div>
                            <h3>Register</h3>
                            <p>Create account & set your car details</p>
                        </div>
                        <div class="step">
                            <div class="step-number">2</div>
                            <h3>Find</h3>
                            <p>Use GPS to find nearby chargers</p>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <h3>Book</h3>
                            <p>Reserve a charger in your desired time</p>
                        </div>
                        <div class="step">
                            <div class="step-number">4</div>
                            <h3>Charge</h3>
                            <p>Drive to station and charge your EV</p>
                        </div>
                        <div class="step">
                            <div class="step-number">5</div>
                            <h3>Rate</h3>
                            <p>Share your experience with others</p>
                        </div>
                    </div>
                </div>

                <div class="tab-content" id="owner-tab">
                    <div class="steps">
                        <div class="step">
                            <div class="step-number">1</div>
                            <h3>Register</h3>
                            <p>Create owner account & add company details</p>
                        </div>
                        <div class="step">
                            <div class="step-number">2</div>
                            <h3>Add Station</h3>
                            <p>Register station with charger details</p>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <h3>Approve</h3>
                            <p>Wait for admin approval (usually 24 hrs)</p>
                        </div>
                        <div class="step">
                            <div class="step-number">4</div>
                            <h3>Monitor</h3>
                            <p>Track bookings and charger status</p>
                        </div>
                        <div class="step">
                            <div class="step-number">5</div>
                            <h3>Earn</h3>
                            <p>Receive revenue from each charging session</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== TESTIMONIALS ===== -->
    <section id="about" class="testimonials">
        <div class="container">
            <h2>What People Say 💬</h2>

            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p>"Found a charger in 2 minutes! Super convenient and affordable."</p>
                    <h4>Raj Patel</h4>
                    <span>EV Owner, Kathmandu</span>
                </div>

                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p>"Great platform for my charging business. Easy to manage and profitable!"</p>
                    <h4>Ram Enterprises</h4>
                    <span>Station Owner, Bhaktapur</span>
                </div>

                <div class="testimonial-card">
                    <div class="stars">
                        <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
                    </div>
                    <p>"Saves me so much time. The GPS tracking is incredibly accurate."</p>
                    <h4>Priya Singh</h4>
                    <span>EV Enthusiast, Lalitpur</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== CTA SECTION ===== -->
    <section class="cta">
        <div class="container">
            <h2>Ready to Get Started? 🚀</h2>
            <p>Join thousands of EV owners and charging station operators</p>

            <div class="cta-buttons">
                <a href="register.php?type=driver" class="btn btn-primary btn-large">
                    <i class="fas fa-user"></i> I'm a Driver
                </a>
                <a href="register.php?type=owner" class="btn btn-secondary btn-large">
                    <i class="fas fa-store"></i> I'm an Owner
                </a>
            </div>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>WattPulse</h4>
                    <p>Making EV charging accessible & affordable for everyone</p>
                </div>
                <div class="footer-col">
                    <h4>Quick Links</h4>
                    <a href="#features">Features</a>
                    <a href="#how-it-works">How It Works</a>
                    <a href="#about">About</a>
                </div>
                <div class="footer-col">
                    <h4>For Users</h4>
                    <a href="login.php">Login</a>
                    <a href="register.php?type=driver">Sign Up</a>
                </div>
                <div class="footer-col">
                    <h4>For Owners</h4>
                    <a href="login.php">Owner Login</a>
                    <a href="register.php?type=owner">Add Station</a>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 WattPulse. All rights reserved.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="assets/js/landing.js"></script>
</body>
</html>