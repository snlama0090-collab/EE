<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

// Require driver login
Auth::requireUserType('driver');

$user_id = Auth::getCurrentUserId();
$db = getDB();

// Server-side initial page — no flicker
$allowed = ['dashboard', 'find-stations', 'bookings', 'favorites', 'profile'];
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
</head>
<body>
    <div class="dashboard-container">
        <!-- SIDEBAR -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-profile">
                <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" class="profile-pic">
                <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
            </div>

            <!-- Navigation — active class set server-side, no flicker -->
            <div class="sidebar-nav">
                <button class="nav-btn<?php echo $page === 'dashboard' ? ' active' : ''; ?>" data-section="dashboard" onclick="loadSection('dashboard')">
                    <i class="fas fa-home"></i> Dashboard
                </button>
                <button class="nav-btn<?php echo $page === 'find-stations' ? ' active' : ''; ?>" data-section="find-stations" onclick="loadSection('find-stations')">
                    <i class="fas fa-map"></i> Find Stations
                </button>
                <button class="nav-btn<?php echo $page === 'bookings' ? ' active' : ''; ?>" data-section="bookings" onclick="loadSection('bookings')">
                    <i class="fas fa-clock"></i> My Bookings
                </button>
                <button class="nav-btn<?php echo $page === 'favorites' ? ' active' : ''; ?>" data-section="favorites" onclick="loadSection('favorites')">
                    <i class="fas fa-heart"></i> Favorites
                </button>
                <button class="nav-btn<?php echo $page === 'profile' ? ' active' : ''; ?>" data-section="profile" onclick="loadSection('profile')">
                    <i class="fas fa-user"></i> Profile
                </button>
            </div>

            <div class="sidebar-logout">
                <button onclick="logout()" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- MAIN CONTENT -->
        <div class="main-content">
            <div class="top-header">
                <div class="header-left">
                    <h1>EV Charging Station</h1>
                </div>
                <div class="header-right">
                    <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" class="header-profile-pic">
                </div>
            </div>

            <!-- CONTENT AREA — rendered server-side on first load, no spinner -->
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

            map = L.map('map').setView([userLocation.lat, userLocation.lng], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
            updateUserMarker(userLocation.lat, userLocation.lng, 'Kathmandu');
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
            if (input) input.value = 'Detecting...';
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    updateUserMarker(pos.coords.latitude, pos.coords.longitude);
                    showStations(); calculateDistancesAndFilter(pos.coords.latitude, pos.coords.longitude);
                    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude)
                        .then(r => r.json()).then(d => {
                            var name = d.address?.city || d.address?.town || d.address?.village || d.address?.county || (pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4));
                            if (input) input.value = name; updateUserMarker(pos.coords.latitude, pos.coords.longitude, name);
                        }).catch(function(){ if (input) input.value = pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4); });
                },
                function(err) { if (input) input.value = ''; alert('Location error: ' + err.message); }
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

        function bookStation(id) { window.location.href = '../index.html#book-' + id; }
        function logout() { window.location.href = '../logout.php'; }

        // --- dashboard.php (battery slider) ---
        function updateBatteryText(val) {
            document.getElementById('slider-val').textContent = val + '%';
            document.getElementById('slider-val').style.color = val > 20 ? '#34C759' : '#FF3B30';
        }

        async function saveBatteryPercent(val) {
            const formData = new FormData();
            formData.append('battery_percent', val);

            try {
                const response = await fetch('sections/dashboard-home.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.status === 'success') {
                    document.getElementById('battery-stat-text').textContent = val + '%';
                }
            } catch (e) {
                console.error('Failed to save battery percent:', e);
            }
        }

        // --- bookings.php (cancel reservation) ---
        function cancelBooking(id) {
            showConfirm('Are you sure you want to cancel this reservation?', function() {
                doCancelBooking(id);
            }, { confirmLabel: 'Cancel Reservation', confirmClass: 'btn-danger' });
        }

        async function doCancelBooking(id) {
            try {
                const response = await fetch(`../../api/bookings.php?id=${id}`, {
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
</body>
</html>