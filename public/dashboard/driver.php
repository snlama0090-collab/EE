<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';
require_once '../../app/helpers/Csrf.php';

// Require driver login
Auth::requireUserType('driver');
Auth::requireProfileComplete(); // Google sign-up: unfinished profiles route to complete-profile.php

$user_id = Auth::getCurrentUserId();

// ponytail: project branding
$project_name = 'WattPulse';
$role_subtitles = ['admin' => 'Admin Portal', 'owner' => 'Station Owner Portal', 'driver' => 'Driver Portal'];
$user_role = Auth::getCurrentUserType();
$role_subtitle = $role_subtitles[$user_role] ?? 'Portal';

$db = getDB();

// Server-side initial page — no flicker
$allowed = ['dashboard', 'find-stations', 'bookings', 'receipts', 'favorites', 'notifications', 'profile', 'support'];
$page = in_array($_GET['page'] ?? '', $allowed) ? $_GET['page'] : 'dashboard';

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Bell data — same scope rules as api/notifications.php (single source: Notifications helper)
require_once '../../app/helpers/Notifications.php';
$notif = Notifications::summary($db, $user_role, $user_id);
$unread = (int) $notif['unread_count'];

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
    <meta name="csrf-token" content="<?php echo htmlspecialchars(Csrf::token()); ?>">
    <script src="../assets/js/csrf.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="role-driver">
<!-- FIXED TOP HEADER (full width) -->
<div class="top-header">
    <div class="header-left">
        <div class="header-brand">
            <span class="brand-name"><?php echo htmlspecialchars($project_name); ?></span>
            <span class="brand-sub"><?php echo htmlspecialchars($role_subtitle); ?></span>
        </div>
    </div>
    <div class="header-right">
        <!-- Theme Toggle -->
        <button type="button" class="header-btn" id="theme-toggle" title="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>
        <!-- Notifications -->
        <button type="button" class="header-btn<?php echo $unread > 0 ? ' has-unread' : ''; ?>" id="notif-btn" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-dot"></span>
            <?php if ($unread > 0): ?><span class="notification-count"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
        </button>
        <div class="dropdown" id="notif-dropdown">
            <div class="dropdown-header">Notifications</div>
            <div class="dropdown-body" id="notif-items">
                <?php if (empty($notif['items'])): ?>
                    <div class="dropdown-item muted">No new notifications</div>
                <?php else: ?>
                    <?php foreach ($notif['items'] as $n): ?>
                    <div class="dropdown-item">
                        <strong><?php echo htmlspecialchars($n['action']); ?></strong><br>
                        <small><?php echo htmlspecialchars(mb_substr((string)($n['details'] ?? ''), 0, 90)); ?></small>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="dropdown-footer" onclick="loadSection('notifications')">View all notifications</div>
        </div>
        <!-- Profile -->
        <div class="header-profile-pic" id="profile-btn" style="display:flex; align-items:center; justify-content:center; background:var(--muted); color:var(--foreground); font-size:14px; cursor:pointer;">
            <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" style="width:32px;height:32px;border-radius:var(--radius);object-fit:cover;">
        </div>
        <div class="dropdown profile-dropdown" id="profile-dropdown">
            <div class="dropdown-user">
                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
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
                <button type="button" class="nav-btn<?php echo $page === 'notifications' ? ' active' : ''; ?>" data-section="notifications" onclick="loadSection('notifications')">
                    <i class="fas fa-bell"></i> <span>Notifications</span>
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

        function loadSection(sectionName, force = false) {
            // Guard: skip reload if already on this section (unless forced)
            if (!force && currentSection === sectionName) return;

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
                            <button onclick="loadSection('${sectionName}', true)" style="margin-top: 16px; padding: 8px 16px; background: #007AFF; color: white; border: none; border-radius: 8px; cursor: pointer;">Try Again</button>
                        </div>`;
                });
        }

        function initializeSection(sectionName) {
            if (sectionName === 'find-stations') {
                setTimeout(() => { initMap(); addStationsToMap(); getDefaultLocationPlaceName(); }, 100);
            }
            if (sectionName === 'bookings') {
                initCountdowns();
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
                .addTo(map).bindPopup('<i class="fas fa-map-marker-alt"></i> ' + (placeName || 'Your Location')).openPopup();
            map.setView([lat, lon], 12);
        }

        // Escape any text read from the DOM before re-inserting into popup HTML.
        // Entities are built at runtime so editors cannot decode them.
        function escapeHtml(str) {
            var map = { 34: '#34;', 38: 'amp;', 39: '#39;', 60: 'lt;', 62: 'gt;' };
            return String(str).replace(/[&<>"']/g, function (c) {
                return '&' + map[c.charCodeAt(0)];
            });
        }

        function addStationsToMap() {
            if (!map) return;
            stationMarkers.forEach(m => { if (map.hasLayer(m)) map.removeLayer(m); });
            stationMarkers = [];
            document.querySelectorAll('.station-card').forEach(card => {
                var lat = parseFloat(card.dataset.latitude), lon = parseFloat(card.dataset.longitude);
                if (isNaN(lat) || isNaN(lon)) return;

                var name = card.querySelector('.station-name')?.textContent || 'Charging Station';
                var city = card.querySelector('.station-city')?.textContent || '';
                var available = card.dataset.available || '0';
                var chargerCount = card.dataset.chargerCount || '0';
                var chargerTypes = card.dataset.chargerType ? card.dataset.chargerType.split(',') : [];

                // Availability color
                var availableNum = parseInt(available, 10);
                var markerColor = availableNum > 0 ? '#34C759' : '#FF3B30';
                var markerText = availableNum > 0 ? '✅ ' + available + ' Available' : '❌ Currently Full';
                var multi = L.icon({
                    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                    popupAnchor: [1, -34],
                    shadowSize: [41, 41]
                });

                var marker = L.marker([lat, lon], { icon: multi }).addTo(map);

                var safeName = escapeHtml(name);
                marker.bindTooltip(safeName, { permanent: false, direction: 'top', opacity: 0.9 });

                var popupHtml = `
                    <div style="font-size:12px; line-height:1.5; min-width:160px;">
                        <strong style="font-size:13px;">${safeName}</strong>
                        ${city ? `<div style="color:#8E8E93;">${escapeHtml(city)}</div>` : ''}
                        <div style="margin-top:4px;">
                            <i class="fas fa-plug"></i> ${chargerCount} Chargers
                            (${available} available)
                        </div>
                        <div style="color:#8E8E93;">
                            ${chargerTypes.slice(0, 3).map(t => escapeHtml(t.trim())).filter(Boolean).join(', ') || 'Standard'}
                        </div>
                        <div style="margin-top:6px; font-weight:600; color:${markerColor};">
                            ${markerText}
                        </div>
                    </div>
                `;
                marker.bindPopup(popupHtml);
                stationMarkers.push(marker);
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
            fetch(`/EE/api/stations.php?id=${stationId}`)
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
                    box.style.position = 'relative';

                    let chargerOptions = station.chargers.map(c => {
                        const label = `#${c.charger_number} — ${c.charger_type} (${c.wattage_kw}kW) — ${c.display_status}`;
                        const disabled = c.bookable ? '' : 'disabled';
                        return `<option value="${c.id}" ${disabled}>${label}</option>`;
                    }).join('');

                    const reviews = station.reviews || [];
                    const reviewsHtml = reviews.length ? reviews.map(rv => `
                        <div style="border-bottom:1px solid var(--border); padding:8px 0;">
                            <div style="display:flex; justify-content:space-between; font-size:12px;">
                                <strong>${escapeHtml(rv.user_name)}</strong>
                                <span style="color:#f5b301;">${'★'.repeat(Number(rv.rating))}${'☆'.repeat(5 - Number(rv.rating))}</span>
                            </div>
                            <div style="font-size:13px; color:var(--gray); margin-top:2px;">${escapeHtml(rv.comment || '')}</div>
                        </div>`).join('') : '<div style="font-size:12px; color:var(--muted-foreground);">No reviews yet — be the first after your session.</div>';

                    box.innerHTML = `
                        <div style="margin-bottom:20px;">
                            <h3 style="margin-bottom:4px;"><i class="fas fa-plug"></i> ${station.name}</h3>
                            <p style="color:var(--gray); font-size:13px;">Pay a flat reservation fee of NPR 50. Battery % and charging cost will be calculated when you start the session.</p>
                            <div style="font-size:12px; color:#f5b301; margin-top:6px;">
                                ★ ${Number(station.average_rating || 0).toFixed(1)} / 5 · ${reviews.length} review${reviews.length === 1 ? '' : 's'}
                            </div>
                        </div>
                        <div style="margin-bottom:16px; max-height:150px; overflow-y:auto; border:1px solid var(--border); border-radius:8px; padding:8px 12px;">
                            <div style="font-size:12px; font-weight:600; margin-bottom:4px;">Reviews</div>
                            ${reviewsHtml}
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">Charger</label>
                            <select id="modal-charger-select" class="sort-select" style="width:100%; margin:0;">
                                ${chargerOptions}
                            </select>
                        </div>
                        <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid var(--border); padding-top:16px;">
                            <button class="btn btn-secondary" id="modal-cancel-btn">Cancel</button>
                            <button class="btn btn-primary" id="modal-confirm-btn">Reserve — NPR 50</button>
                        </div>
                    `;

                    // Favorite toggle (top-right of modal)
                    const favBtn = document.createElement('button');
                    favBtn.className = 'fav-btn';
                    favBtn.dataset.favorite = station.is_favorite ? '1' : '0';
                    favBtn.style.cssText = 'position: absolute; top: 16px; right: 16px; background: none; border: none; cursor: pointer; font-size: 20px; padding: 4px; line-height: 1;';
                    favBtn.innerHTML = `<i class="fa${station.is_favorite ? 's' : 'r'} fa-heart" style="color:${station.is_favorite ? '#FF3B30' : 'var(--muted-foreground)'}"></i>`;
                    favBtn.onclick = function() { toggleFavorite(this, station.id); };
                    box.appendChild(favBtn);

                    overlay.appendChild(box);
                    document.body.appendChild(overlay);
                    requestAnimationFrame(() => overlay.classList.add('show'));

                    const close = () => { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 200); };
                    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
                    box.querySelector('#modal-cancel-btn').onclick = close;

                    box.querySelector('#modal-confirm-btn').onclick = function() {
                        const chargerId = parseInt(box.querySelector('#modal-charger-select').value);

                        this.disabled = true;
                        this.textContent = 'Reserving...';

                        fetch('/EE/api/bookings.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'initiate_payment', charger_id: chargerId })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status !== 'success') {
                                this.disabled = false;
                                this.textContent = 'Reserve — NPR 50';
                                showAlert(res.message || 'Booking failed.', 'error');
                                return;
                            }

                            // Show payment confirmation preview
                            close();
                            const data = res.data;
                            // Khalti mode: the API returned a hosted payment URL —
                            // leave the app and pay there; lookup-verified on return.
                            // Simulated mode has no payment_url and falls through.
                            if (data.payment_url) {
                                window.location.href = data.payment_url;
                                return;
                            }
                            showConfirm(
                                `Reservation Summary\nFee: NPR ${data.estimated_cost.toFixed(2)}\n\nProceed with payment?`,
                                function() {
                                    confirmPayment(data.booking_id);
                                },
                                { confirmLabel: `Pay NPR ${data.estimated_cost.toFixed(2)}`, confirmClass: 'btn-primary' }
                            );
                        })
                        .catch(() => {
                            this.disabled = false;
                            this.textContent = 'Reserve — NPR 50';
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
                const response = await fetch('/EE/api/bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'confirm_payment', booking_id: bookingId })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert('Payment confirmed! Your booking is reserved — show up and the station will start your session.', 'success');
                    loadSection('bookings', true);
                    startPollingIfNeeded();
                } else {
                    showAlert(result.message || 'Payment confirmation failed.', 'error');
                }
            } catch (e) {
                showAlert('Network error during payment confirmation.', 'error');
            }
        }

        // --- initiate_charging_payment / confirm_charging_payment flow ---
        function startCharging(bookingId) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            const box = document.createElement('div');
            box.className = 'modal-box';
            box.style.textAlign = 'left';

            box.innerHTML = `
                <div style="margin-bottom:20px;">
                    <h3 style="margin-bottom:4px;"><i class="fas fa-plug"></i> Start Charging</h3>
                    <p style="color:var(--gray); font-size:13px;">Enter your current battery percentage to calculate the charging cost.</p>
                </div>
                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">Current Battery %</label>
                    <input type="number" id="charging-battery-input" class="location-input" style="width:100%;" min="1" max="100" placeholder="Enter your current battery %" value="">
                </div>
                <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid var(--border); padding-top:16px;">
                    <button class="btn btn-secondary" id="charging-cancel-btn">Cancel</button>
                    <button class="btn btn-primary" id="charging-quote-btn">Get Quote</button>
                </div>
            `;

            overlay.appendChild(box);
            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('show'));

            const close = () => { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 200); };
            overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
            box.querySelector('#charging-cancel-btn').onclick = close;

            box.querySelector('#charging-quote-btn').onclick = function() {
                const batteryPct = parseInt(box.querySelector('#charging-battery-input').value);
                if (!batteryPct || batteryPct < 1 || batteryPct > 100) {
                    showAlert('Please enter a valid battery percentage (1–100).', 'error');
                    return;
                }

                this.disabled = true;
                this.textContent = 'Calculating...';

                fetch('/EE/api/bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'initiate_charging_payment', booking_id: bookingId, battery_percent: batteryPct })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'success') {
                        this.disabled = false;
                        this.textContent = 'Get Quote';
                        showAlert(res.message || 'Failed to calculate cost.', 'error');
                        return;
                    }

                    close();
                    const data = res.data;
                    const total = 50 + data.charging_cost;
                    showConfirm(
                        `Charging Cost Summary\nReservation fee: NPR 50.00 (already paid)\nCharging fee: NPR ${data.charging_cost.toFixed(2)} — this is what you'll pay now\n────────────────\nTotal session cost: NPR ${total.toFixed(2)}\n\nProceed with payment?`,
                        function() {
                            confirmChargingPayment(bookingId, batteryPct);
                        },
                        { confirmLabel: `Pay NPR ${data.charging_cost.toFixed(2)}`, confirmClass: 'btn-primary' }
                    );
                })
                .catch(() => {
                    this.disabled = false;
                    this.textContent = 'Get Quote';
                    showAlert('Network error. Please try again.', 'error');
                });
            };
        }

        async function confirmChargingPayment(bookingId, batteryPercent) {
            try {
                const response = await fetch('/EE/api/bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'confirm_charging_payment', booking_id: bookingId, battery_percent: batteryPercent })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert('Payment confirmed! Charging session started.', 'success');
                    loadSection('bookings', true);
                    startPollingIfNeeded();
                } else {
                    showAlert(result.message || 'Failed to start charging.', 'error');
                }
            } catch (e) {
                showAlert('Network error during payment confirmation.', 'error');
            }
        }

        // --- shared countdown helper ---
        let countdownIntervals = [];

        function startCountdown(targetIso, element, onExpire) {
            function tick() {
                const diff = new Date(targetIso.replace(' ', 'T') + '+05:45').getTime() - Date.now();
                if (diff <= 0) {
                    element.textContent = 'Expired';
                    if (onExpire) onExpire();
                    return;
                }
                const m = Math.floor(diff / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                element.textContent = m + ':' + String(s).padStart(2, '0');
            }
            tick();
            const id = setInterval(tick, 1000);
            countdownIntervals.push(id);
            return id;
        }

        // Wire up countdowns when the bookings section loads
        function initCountdowns() {
            // Clear any previous countdown intervals so reloads don't stack timers
            countdownIntervals.forEach(clearInterval);
            countdownIntervals = [];
            document.querySelectorAll('.countdown[data-countdown-to]').forEach(el => {
                const target = el.dataset.countdownTo;
                if (!target) return;
                startCountdown(target, el, function() {
                    if (currentSection === 'bookings') loadSection('bookings', true);
                });
            });
        }

        // --- stop charging (no refund) ---
        function stopCharging(bookingId) {
            // kWh-billing fix (audit #8, 2026-08-31): capture actual end-battery % so the
            // charging_sessions record reflects real kWh consumed (record-accuracy only —
            // the already-captured payment is NOT refunded/changed).
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            const box = document.createElement('div');
            box.className = 'modal-box';
            box.style.textAlign = 'left';
            box.innerHTML = `
                <div style="margin-bottom:20px;">
                    <h3 style="margin-bottom:4px;"><i class="fas fa-stop-circle" style="color:#FF3B30;"></i> Stop Charging</h3>
                    <p style="color:var(--gray); font-size:13px;">Money already paid will NOT be refunded. Enter your current battery % for accurate session records.</p>
                </div>
                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">End Battery % (when you stop)</label>
                    <input type="number" id="stop-battery-input" class="location-input" style="width:100%;" min="1" max="100" placeholder="Enter your current battery %" value="">
                </div>
                <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid var(--border); padding-top:16px;">
                    <button class="btn btn-secondary" id="stop-cancel-btn">Cancel</button>
                    <button class="btn btn-danger" id="stop-confirm-btn">Stop & No Refund</button>
                </div>
            `;
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('show'));

            const close = () => { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 200); };
            overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
            box.querySelector('#stop-cancel-btn').onclick = close;
            box.querySelector('#stop-confirm-btn').onclick = function() {
                const endPct = parseInt(box.querySelector('#stop-battery-input').value, 10);
                if (!endPct || endPct < 1 || endPct > 100) {
                    showToast('Please enter a valid battery percentage (1–100).', 'error');
                    return;
                }
                close();
                doStopCharging(bookingId, endPct);
            };
        }

        async function doStopCharging(bookingId, endBatteryPercent) {
            try {
                const response = await fetch('/EE/api/bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'stop_session', booking_id: bookingId, end_battery_percent: endBatteryPercent })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showToast('Charging stopped. No refund issued.', 'info');
                    loadSection('bookings', true);
                } else {
                    showToast(result.message || 'Failed to stop charging.', 'error');
                }
            } catch (e) {
                showToast('Network error. Try again.', 'error');
            }
        }

        // --- polling loop for active booking timers ---
        let pollingInterval = null;

        function startPollingIfNeeded() {
            // Only poll if there's an active charging/pending_payment booking
            fetch('/EE/api/bookings.php')
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
            fetch('/EE/api/bookings.php')
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'success') return;
                    const active = (res.data || []).filter(b => b.status === 'pending_payment' || b.status === 'charging');
                    if (active.length === 0) {
                        stopPolling();
                        // Timer hit zero — reload section to get completed template
                        if (currentSection === 'bookings' || currentSection === 'dashboard') {
                            loadSection(currentSection, true);
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
                            display.innerHTML = `<span style="color:#FF9500;font-weight:600;"><i class="fas fa-plug"></i> Owner connecting... ${m}:${String(s).padStart(2,'0')} buffer remaining</span>`;
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
                                loadSection(currentSection, true);
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
                const response = await fetch(`/EE/api/bookings.php?id=${id}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    showAlert('Reservation cancelled successfully.', 'success');
                    loadSection('bookings', true);
                } else {
                    showAlert(result.message || 'Failed to cancel reservation.', 'error');
                }
            } catch (e) {
                showAlert('Network error. Try again.', 'error');
            }
        }

        // --- favorites.php ---
        // Toggle favorite on/off (used by find-stations cards + detail modal)
        async function toggleFavorite(btn, stationId) {
            const isFav = btn.dataset.favorite === '1';
            const action = isFav ? 'remove' : 'add';
            const formData = new FormData();
            formData.append('action', action);
            formData.append('station_id', stationId);
            try {
                const response = await fetch('sections/favorites.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.status === 'success') {
                    btn.dataset.favorite = isFav ? '0' : '1';
                    const icon = btn.querySelector('i');
                    if (icon) {
                        icon.className = isFav ? 'far fa-heart' : 'fas fa-heart';
                        icon.style.color = isFav ? 'var(--muted-foreground)' : '#FF3B30';
                    }
                } else {
                    showToast(result.message || 'Failed to update favorite.', 'error');
                }
            } catch (e) {
                showToast('Error updating favorites.', 'error');
            }
        }

        // Explicit add (standalone contexts)
        async function addFavorite(btn, stationId) {
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('station_id', stationId);
            try {
                const response = await fetch('sections/favorites.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.status === 'success') {
                    btn.dataset.favorite = '1';
                    const icon = btn.querySelector('i');
                    if (icon) { icon.className = 'fas fa-heart'; icon.style.color = '#FF3B30'; }
                } else {
                    showToast(result.message || 'Failed to add favorite.', 'error');
                }
            } catch (e) {
                showToast('Error updating favorites.', 'error');
            }
        }

        function removeFavorite(stationId) {
            showConfirm('Remove this station from your favorites?', function() {
                doRemoveFavorite(stationId);
            }, { confirmLabel: 'Remove', confirmClass: 'btn-danger' });
        }

        async function doRemoveFavorite(stationId) {
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('station_id', stationId);
            try {
                const response = await fetch('sections/favorites.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.status === 'success') {
                    loadSection('favorites');
                } else {
                    showToast(result.message || 'Failed to remove.', 'error');
                }
            } catch (e) {
                showToast('Error updating favorites list.', 'error');
            }
        }

        function bookFavorite(stationId) {
            history.pushState(null, '', '#find-stations');
            loadSection('find-stations');
        }

        // --- reviews (Phase 1): rate a finished session from booking history ---
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        var reviewRating = 0;
        var reviewBookingId = 0;
        function rateBooking(bookingId, stationName) {
            reviewBookingId = bookingId;
            reviewRating = 0;
            document.getElementById('review-station').textContent = 'Station: ' + stationName;
            setStars(0);
            document.getElementById('review-comment').value = '';
            document.getElementById('review-modal').classList.add('show');
        }
        function setStars(n) {
            reviewRating = n;
            var stars = document.querySelectorAll('#review-stars span');
            for (var i = 0; i < stars.length; i++) {
                stars[i].style.color = (i < n) ? '#f5b301' : '#d1d5db';
            }
        }
        function closeReviewModal() {
            document.getElementById('review-modal').classList.remove('show');
        }
        function submitReview() {
            if (!reviewRating) { showToast('Please choose a star rating.', 'error'); return; }
            var comment = document.getElementById('review-comment').value.trim();
            if (!comment) { showToast('Please write a short review.', 'error'); return; }
            var btn = document.getElementById('review-submit');
            btn.disabled = true;
            fetch('/EE/api/reviews.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ booking_id: reviewBookingId, rating: reviewRating, comment: comment })
            }).then(function (r) { return r.json(); }).then(function (res) {
                btn.disabled = false;
                if (res.status === 'success') {
                    closeReviewModal();
                    showToast('Review submitted — thank you!', 'success');
                    loadSection('bookings', true);
                } else {
                    showToast(res.message || 'Could not submit review.', 'error');
                }
            }).catch(function () { btn.disabled = false; showToast('Network error — try again.', 'error'); });
        }

        // --- preset avatar picker (profile form) ---
        function selectPreset(key, el) {
            var inp = document.getElementById('preset-input');
            if (!inp) return;
            if (inp.value === key) { inp.value = ''; el.style.borderColor = 'transparent'; return; } // click again = deselect
            inp.value = key;
            var imgs = document.querySelectorAll('.preset-picker img');
            for (var i = 0; i < imgs.length; i++) imgs[i].style.borderColor = 'transparent';
            el.style.borderColor = 'var(--primary)';
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
                } else if (result.error_code === 'upload_failed') {
                    showAlert('Failed to upload your profile picture — your profile was NOT saved. Please try a JPG/PNG under 5MB.', 'error');
                } else {
                    showAlert(result.message || 'Failed to update profile.', 'error');
                }
            } catch (e) {
                console.error(e);
                showAlert('Error updating profile. Try again.', 'error');
            }
        }
    </script>
    <script>window.userRole='<?php echo $user_role; ?>';</script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>