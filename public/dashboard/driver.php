<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

// Require driver login
Auth::requireUserType('driver');

$user_id = Auth::getCurrentUserId();
$db = getDB();

// Server-side initial page — no flicker
$allowed = ['dashboard', 'find-stations', 'bookings', 'receipts', 'favorites', 'profile', 'support'];
$page = in_array($_GET['page'] ?? '', $allowed) ? $_GET['page'] : 'dashboard';

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get user profile picture
$profilePicPath = '../assets/img/default-avatar.svg';
$profilePicAbsolute = PUBLIC_PATH . "/assets/uploads/pfp/{$user_id}.jpg";
if (file_exists($profilePicAbsolute)) {
    $profilePicPath = "../assets/uploads/pfp/{$user_id}.jpg";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - EV Charging Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<!-- FIXED TOP HEADER (full width) -->
<div class="top-header">
    <div class="header-left">
        <div class="header-brand">
            <span class="brand-name">Zenith</span>
            <span class="brand-sub">Dashboard</span>
        </div>
    </div>
    <div class="header-right">
        <!-- Theme Toggle -->
        <button type="button" class="header-btn" id="theme-toggle" title="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>
        <!-- Notifications -->
        <button type="button" class="header-btn" id="notif-btn" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-dot"></span>
        </button>
        <div class="dropdown" id="notif-dropdown">
            <div class="dropdown-header">Notifications</div>
            <div class="dropdown-body">
                <div class="dropdown-item">No new notifications</div>
            </div>
            <div class="dropdown-footer">View all notifications</div>
        </div>
        <!-- Profile -->
        <div class="header-profile-pic" id="profile-btn" style="display:flex; align-items:center; justify-content:center; background:var(--muted); color:var(--foreground); font-size:14px; cursor:pointer;">
            <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" style="width:32px;height:32px;border-radius:var(--radius);object-fit:cover;">
        </div>
        <div class="dropdown profile-dropdown" id="profile-dropdown">
            <div class="dropdown-user">
                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="user-email">driver@evcharge.com</div>
            </div>
            <div class="dropdown-body">
                <div class="dropdown-item" onclick="loadSection('profile')"><i class="fas fa-user" style="width:16px;"></i> Profile</div>
            </div>
            <div class="dropdown-footer" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</div>
        </div>
    </div>
</div>

<div class="dashboard-container">
    <!-- SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <button type="button" class="sidebar-toggle-btn" id="sidebar-toggle" title="Toggle sidebar">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="sidebar-inner">
            <div class="sidebar-profile">
                <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" class="profile-pic">
                <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
            </div>

            <!-- Navigation — active class set server-side, no flicker -->
            <div class="sidebar-nav">
                <button type="button" class="nav-btn<?php echo $page === 'dashboard' ? ' active' : ''; ?>" data-section="dashboard" onclick="loadSection('dashboard')">
                    <i class="fas fa-home"></i> <span>My Hub</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'find-stations' ? ' active' : ''; ?>" data-section="find-stations" onclick="loadSection('find-stations')">
                    <i class="fas fa-map"></i> <span>Find Stations</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'bookings' ? ' active' : ''; ?>" data-section="bookings" onclick="loadSection('bookings')">
                    <i class="fas fa-clock"></i> <span>Charging Sessions</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'receipts' ? ' active' : ''; ?>" data-section="receipts" onclick="loadSection('receipts')">
                    <i class="fas fa-receipt"></i> <span>My Receipts</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'favorites' ? ' active' : ''; ?>" data-section="favorites" onclick="loadSection('favorites')">
                    <i class="fas fa-heart"></i> <span>Favorites</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'profile' ? ' active' : ''; ?>" data-section="profile" onclick="loadSection('profile')">
                    <i class="fas fa-user"></i> <span>Profile</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'support' ? ' active' : ''; ?>" data-section="support" onclick="loadSection('support')">
                    <i class="fas fa-question-circle"></i> <span>Support</span>
                </button>
            </div>

            <div class="sidebar-logout">
                <button type="button" onclick="logout()" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </button>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div id="content-area">
            <?php include "sections/{$page}.php"; ?>
        </div>
    </div>
</div>

    <script src="../assets/js/modal.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map = null;
        let userMarker = null;
        let stationMarkers = [];
        let userLocation = { lat: 27.7172, lng: 85.3240 };
        let currentSection = '<?php echo $page; ?>';

        function loadSection(sectionName) {
            // Guard: do nothing if already on this section
            if (currentSection === sectionName) return;

            // Stop polling when switching sections to avoid stale network strain
            stopPolling();

            // Update URL without reloading
            if (currentSection !== sectionName) {
                history.pushState(null, '', `?page=${sectionName}`);
            }

            currentSection = sectionName;
            const contentArea = document.getElementById('content-area');

            // Show loading briefly
            contentArea.innerHTML = '<div style="padding: 32px; text-align: center; color: #8E8E93;"><i class="fas fa-spinner fa-spin" style="font-size: 48px; display: block; margin-bottom: 16px;"></i><p>Loading...</p></div>';

            // Update nav
            document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
            const activeBtn = document.querySelector(`.nav-btn[data-section="${sectionName}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            // Fetch section content
            fetch(`sections/${sectionName}.php`)
                .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(html => { contentArea.innerHTML = html; initializeSection(sectionName); })
                .catch(() => {
                    contentArea.innerHTML = `
                        <div style="padding: 32px; text-align: center; color: #FF3B30;">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                            <p>Failed to load this section</p>
                            <button onclick="loadSection('${sectionName}')" style="margin-top: 16px; padding: 8px 16px; background: #007AFF; color: white; border: none; border-radius: 8px; cursor: pointer;">Try Again</button>
                        </div>`;
                });
        }

        function initializeSection(sectionName) {
            if (sectionName === 'find-stations') {
                setTimeout(() => { initMap(); addStationsToMap(); getDefaultLocationPlaceName(); }, 100);
            }
        }

        // Run once on initial page load for the pre-rendered section
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { initializeSection(currentSection); });
        } else {
            initializeSection(currentSection);
        }

        // Map functions
        function getDefaultLocationPlaceName() {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${userLocation.lat}&lon=${userLocation.lng}`)
                .then(r => r.json())
                .then(data => {
                    const name = data.address?.city || data.address?.town || data.address?.village || data.address?.county || 'Kathmandu';
                    updateUserMarker(userLocation.lat, userLocation.lng, name);
                }).catch(() => {});
        }

        function initMap() {
            const el = document.getElementById('map');
            if (!el) return;
            if (map) { map.remove(); map = null; userMarker = null; stationMarkers = []; }

            // Remove the static placeholder child so it doesn't show below the real map tiles
            const placeholder = document.querySelector('#map .map-placeholder');
            if (placeholder) placeholder.remove();

            map = L.map('map', { scrollWheelZoom: false }).setView([userLocation.lat, userLocation.lng], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
            updateUserMarker(userLocation.lat, userLocation.lng, 'Kathmandu');
            map.on('click', function() { map.scrollWheelZoom.enable(); });
            document.addEventListener('click', function(e) { if (!e.target.closest('#map')) { map.scrollWheelZoom.disable(); } });
        }

        function updateUserMarker(lat, lon, placeName) {
            userLocation.lat = lat; userLocation.lng = lon;
            if (!map) return;
            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.circleMarker([lat, lon], { radius: 8, fillColor: '#FF6B6B', color: '#FF8E72', weight: 3, opacity: 1, fillOpacity: 0.8 })
                .addTo(map).bindPopup('📍 ' + (placeName || 'Your Location')).openPopup();
            map.setView([lat, lon], 12);
        }

        function addStationsToMap() {
            if (!map) return;
            stationMarkers.forEach(m => { if (map.hasLayer(m)) map.removeLayer(m); });
            stationMarkers = [];
            document.querySelectorAll('.station-card').forEach(card => {
                var lat = parseFloat(card.dataset.latitude), lon = parseFloat(card.dataset.longitude);
                if (isNaN(lat) || isNaN(lon)) return;
                stationMarkers.push(L.marker([lat, lon]).addTo(map));
            });
            if (userMarker && stationMarkers.length > 0) {
                var g = L.featureGroup([userMarker, ...stationMarkers]);
                map.fitBounds(g.getBounds(), { padding: [50, 50] });
            }
        }

        function searchStations() {
            var loc = document.getElementById('location-input')?.value;
            if (!loc) { alert('Please enter a location'); return; }
            showStations(); document.getElementById('range-filter').value = '2'; document.getElementById('charger-filter').value = ''; filterStations();
        }

        function detectLocation() {
            if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
            var input = document.getElementById('location-input');
            var btn = document.querySelector('[onclick="detectLocation()"]');
            if (input) input.value = 'Detecting...';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Detecting...'; }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    updateUserMarker(pos.coords.latitude, pos.coords.longitude);
                    showStations(); calculateDistancesAndFilter(pos.coords.latitude, pos.coords.longitude);
                    // Default range filter to 2km after location detection
                    var rangeEl = document.getElementById('range-filter');
                    if (rangeEl) { rangeEl.value = '2'; filterStations(); }
                    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude)
                        .then(r => r.json()).then(d => {
                            var name = d.address?.city || d.address?.town || d.address?.village || d.address?.county || (pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4));
                            if (input) input.value = name; updateUserMarker(pos.coords.latitude, pos.coords.longitude, name);
                        }).catch(function(){ if (input) input.value = pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4); })
                        .finally(function(){ if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Detect location'; } });
                },
                function(err) {
                    if (input) input.value = '';
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-location-crosshairs"></i> Detect location'; }
                    alert('Location error: ' + err.message);
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
            );
        }

        function calculateDistancesAndFilter(ulat, ulon) {
            document.querySelectorAll('.station-card').forEach(c => {
                var slat = parseFloat(c.dataset.latitude), slon = parseFloat(c.dataset.longitude);
                var dist = (isNaN(slat) || isNaN(slon)) ? 0 : calculateDistance(ulat, ulon, slat, slon);
                c.dataset.distance = dist.toFixed(1);
                var span = c.querySelector('.station-distance');
                if (span) span.textContent = dist.toFixed(1);
            });
            addStationsToMap(); filterStations();
        }

        function calculateDistance(lat1, lon1, lat2, lon2) {
            var R = 6371, dLat = (lat2-lat1)*Math.PI/180, dLon = (lon2-lon1)*Math.PI/180;
            var a = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)*Math.sin(dLon/2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        function showStations() { var el = document.getElementById('stations-section'); if (el) el.style.display = 'flex'; }

        function filterStations() {
            var range = parseFloat(document.getElementById('range-filter')?.value) || 999, filter = document.getElementById('charger-filter')?.value, visible = 0;
            document.querySelectorAll('.station-card').forEach(c => {
                var dist = parseFloat(c.dataset.distance) || 0, match = filter ? (c.dataset.chargerType || '').includes(filter) : true;
                if (dist <= range && match) { c.style.display = 'flex'; visible++; } else { c.style.display = 'none'; }
            });
            var el = document.getElementById('stations-section'), msg = document.getElementById('no-stations-msg');
            if (visible === 0 && el) {
                if (!msg) { msg = document.createElement('div'); msg.id = 'no-stations-msg'; msg.textContent = 'No stations found within your selected range.'; msg.style.cssText = 'text-align:center;color:#8E8E93;padding:32px;font-size:14px;width:100%;'; el.appendChild(msg); }
            } else { if (msg) msg.remove(); }
        }

        function sortStations(sortBy) {
            var cards = Array.from(document.querySelectorAll('.station-card'));
            cards.sort(function(a,b){ return sortBy === 'distance' ? parseFloat(a.dataset.distance)-parseFloat(b.dataset.distance) : 0; });
            var container = document.getElementById('stations-section');
            if (container) cards.forEach(function(c){ if (c.style.display !== 'none') container.appendChild(c); });
        }


        // --- booking modal (P2P prepaid flow) ---
        function bookStation(stationId) {
            fetch(`../api/stations.php?id=${stationId}`)
                .then(r => r.json())
                .then(result => {
                    if (result.status !== 'success') throw new Error(result.message);
                    const station = result.data;
                    const bookable = (station.chargers || []).filter(c => c.bookable);
                    if (bookable.length === 0) {
                        showAlert('No chargers currently available at this station.', 'error');
                        return;
                    }

                    const overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    const box = document.createElement('div');
                    box.className = 'modal-box';
                    box.style.textAlign = 'left';

                    let chargerOptions = station.chargers.map(c => {
                        const label = `#${c.charger_number} — ${c.charger_type} (${c.wattage_kw}kW) — ${c.display_status}`;
                        const disabled = c.bookable ? '' : 'disabled';
                        return `<option value="${c.id}" ${disabled}>${label}</option>`;
                    }).join('');

                    box.innerHTML = `
                        <div style="margin-bottom:20px;">
                            <h3 style="margin-bottom:4px;">🔌 ${station.name}</h3>
                            <p style="color:var(--gray); font-size:13px;">Select a charger and enter your current battery % to get a prepaid quote.</p>
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">Charger</label>
                            <select id="modal-charger-select" class="sort-select" style="width:100%; margin:0;">
                                ${chargerOptions}
                            </select>
                        </div>
                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">Current Battery %</label>
                            <input type="number" id="modal-battery-input" class="location-input" style="width:100%;" min="1" max="100" placeholder="Enter your current battery %" value="">
                        </div>
                        <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid var(--border); padding-top:16px;">
                            <button class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
                            <button class="btn btn-primary" id="modal-confirm-btn">Get Quote & Pay</button>
                        </div>
                    `;

                    overlay.appendChild(box);
                    document.body.appendChild(overlay);
                    requestAnimationFrame(() => overlay.classList.add('show'));

                    const close = () => { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 200); };
                    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
                    box.querySelector('#modal-cancel-btn').onclick = close;

                    box.querySelector('#modal-confirm-btn').onclick = function() {
                        const chargerId = parseInt(box.querySelector('#modal-charger-select').value);
                        const batteryPct = parseInt(box.querySelector('#modal-battery-input').value);
                        if (!batteryPct || batteryPct < 1 || batteryPct > 100) {
                            showAlert('Please enter your current battery percentage (1–100).', 'error');
                            return;
                        }

                        this.disabled = true;
                        this.textContent = 'Creating booking...';

                        fetch('../api/bookings.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'initiate_payment', charger_id: chargerId, current_percentage: batteryPct })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status !== 'success') {
                                this.disabled = false;
                                this.textContent = 'Get Quote & Pay';
                                showAlert(res.message || 'Booking failed.', 'error');
                                return;
                            }

                            // Show payment confirmation preview
                            close();
                            const data = res.data;
                            showConfirm(
                                `Prepaid Booking Summary\nEstimated cost: NPR ${data.estimated_cost.toFixed(2)}\nCharge time: ~${data.charge_time_minutes} min\n\nProceed with payment?`,
                                function() {
                                    confirmPayment(data.booking_id);
                                },
                                { confirmLabel: `Pay NPR ${data.estimated_cost.toFixed(2)}`, confirmClass: 'btn-primary' }
                            );
                        })
                        .catch(() => {
                            this.disabled = false;
                            this.textContent = 'Get Quote & Pay';
                            showAlert('Network error. Please try again.', 'error');
                        });
                    };
                })
                .catch(() => {
                    showAlert('Failed to load station details.', 'error');
                });
        }

        async function confirmPayment(bookingId) {
            try {
                const response = await fetch('../api/bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'confirm_payment', booking_id: bookingId })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert('Payment confirmed! Charging session started.', 'success');
                    loadSection('bookings');
                    startPollingIfNeeded();
                } else {
                    showAlert(result.message || 'Payment confirmation failed.', 'error');
                }
            } catch (e) {
                showAlert('Network error during payment confirmation.', 'error');
            }
        }

        // --- polling loop for active booking timers ---
        let pollingInterval = null;

        function startPollingIfNeeded() {
            // Only poll if there's an active charging/pending_payment booking
            fetch('../api/bookings.php')
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'success') return;
                    const active = (res.data || []).filter(b => b.status === 'pending_payment' || b.status === 'charging');
                    if (active.length > 0) {
                        if (pollingInterval) return; // already running
                        pollingInterval = setInterval(pollTick, 12000);
                    } else {
                        stopPolling();
                    }
                })
                .catch(() => {});
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        function pollTick() {
            fetch('../api/bookings.php')
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'success') return;
                    const active = (res.data || []).filter(b => b.status === 'pending_payment' || b.status === 'charging');
                    if (active.length === 0) {
                        stopPolling();
                        // Timer hit zero — reload section to get completed template
                        if (currentSection === 'bookings' || currentSection === 'dashboard') {
                            loadSection(currentSection);
                        }
                        return;
                    }
                    // Update any visible countdown displays
                    document.querySelectorAll('[data-booking-id]').forEach(el => {
                        const bid = parseInt(el.dataset.bookingId);
                        const booking = active.find(b => b.id === bid);
                        if (!booking || !booking.buffer_ends_at) return;
                        const now = Date.now();
                        const bufEnd = new Date(booking.buffer_ends_at.replace(' ', 'T') + '+05:45').getTime();
                        const sessEnd = new Date(booking.session_ends_at.replace(' ', 'T') + '+05:45').getTime();

                        let display = el.querySelector('.timer-display');
                        if (!display) {
                            display = document.createElement('div');
                            display.className = 'timer-display';
                            el.querySelector('.booking-status-area')?.appendChild(display);
                        }

                        if (now < bufEnd) {
                            // Buffer phase — warning
                            const sec = Math.max(0, Math.floor((bufEnd - now) / 1000));
                            const m = Math.floor(sec / 60);
                            const s = sec % 60;
                            el.style.borderLeftColor = '#FF9500';
                            display.innerHTML = `<span style="color:#FF9500;font-weight:600;">🔌 Owner connecting... ${m}:${String(s).padStart(2,'0')} buffer remaining</span>`;
                        } else if (now < sessEnd) {
                            // Active charging — green countdown
                            const sec = Math.max(0, Math.floor((sessEnd - now) / 1000));
                            const m = Math.floor(sec / 60);
                            const s = sec % 60;
                            el.style.borderLeftColor = '#34C759';
                            display.innerHTML = `<span style="color:#34C759;font-weight:600;">⚡ Charging — ${m}:${String(s).padStart(2,'0')} remaining</span>`;
                        } else {
                            // Timer expired — trigger reload
                            stopPolling();
                            if (currentSection === 'bookings' || currentSection === 'dashboard') {
                                loadSection(currentSection);
                            }
                        }
                    });
                })
                .catch(() => {});
        }

        // Start polling on page load if needed
        document.addEventListener('DOMContentLoaded', startPollingIfNeeded);
        function logout() { window.location.href = '../logout.php'; }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }

        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            var sidebar = document.getElementById('sidebar');
            var menuBtn = document.getElementById('mobile-menu-btn');
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Show mobile menu button on small screens
        function checkMobile() {
            var btn = document.getElementById('mobile-menu-btn');
            if (btn) btn.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
        }
        window.addEventListener('resize', checkMobile);
        document.addEventListener('DOMContentLoaded', checkMobile);


        // --- bookings.php (cancel reservation) ---
        function cancelBooking(id) {
            showConfirm('Are you sure you want to cancel this reservation?', function() {
                doCancelBooking(id);
            }, { confirmLabel: 'Cancel Reservation', confirmClass: 'btn-danger' });
        }

        async function doCancelBooking(id) {
            try {
                const response = await fetch(`../api/bookings.php?id=${id}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    showAlert('Reservation cancelled successfully.', 'success');
                    loadSection('bookings');
                } else {
                    showAlert(result.message || 'Failed to cancel reservation.', 'error');
                }
            } catch (e) {
                showAlert('Network error. Try again.', 'error');
            }
        }

        // --- favorites.php ---
        function removeFavorite(stationId) {
            showConfirm('Remove this station from your favorites?', function() {
                doRemoveFavorite(stationId);
            }, { confirmLabel: 'Remove', confirmClass: 'btn-danger' });
        }

        async function doRemoveFavorite(stationId) {
            const formData = new FormData();
            formData.append('station_id', stationId);

            try {
                const response = await fetch('sections/favorites.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.status === 'success') {
                    loadSection('favorites');
                } else {
                    showAlert(result.message || 'Failed to remove.', 'error');
                }
            } catch (e) {
                showAlert('Error updating favorites list.', 'error');
            }
        }

        function bookFavorite(stationId) {
            history.pushState(null, '', '#find-stations');
            loadSection('find-stations');
        }

        // --- profile.php (driver profile form) ---
        async function saveProfile(event) {
            event.preventDefault();
            
            const form = document.getElementById('driver-profile-form');
            const formData = new FormData(form);

            try {
                const response = await fetch('sections/profile.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    showAlert(result.message, 'success');
                    setTimeout(function() { location.reload(); }, 500);
                } else {
                    showAlert(result.message || 'Failed to update profile.', 'error');
                }
            } catch (e) {
                console.error(e);
                showAlert('Error updating profile. Try again.', 'error');
            }
        }
    </script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>