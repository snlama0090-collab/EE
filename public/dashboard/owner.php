<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

// Require owner login
Auth::requireUserType('owner');

$user_id = Auth::getCurrentUserId();

// Server-side initial page — no flicker
$allowed = ['overview', 'financials', 'stations', 'bookings', 'profile'];
$page = in_array($_GET['page'] ?? '', $allowed) ? $_GET['page'] : 'overview';
$db = getDB();

// Get owner details
$stmt = $db->prepare("SELECT * FROM owners WHERE id = ?");
$stmt->execute([$user_id]);
$owner = $stmt->fetch();

// Profile picture path
$profilePicPath = '../assets/img/default-avatar.svg';
$profilePicAbsolute = PUBLIC_PATH . "/assets/uploads/pfp/owner_{$user_id}.jpg";
if (file_exists($profilePicAbsolute)) {
    $profilePicPath = "../assets/uploads/pfp/owner_{$user_id}.jpg";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - EV Charging Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        /* Owner-specific overrides */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .form-full { grid-column: 1 / -1; }
        .form-control-group { 
            display: flex; 
            flex-direction: column; 
            gap: 6px;
        }
        .form-control-group label { 
            font-size: 13px; 
            font-weight: 600;
            color: var(--foreground);
        }
        .charger-config-row {
            display: flex; gap: 8px; align-items: center; margin-bottom: 8px;
        }
        .status-toggle {
            cursor: pointer;
            padding: 6px 12px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--muted);
            font-size: 12px;
            font-weight: 600;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-toggle.active {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: #166534;
        }
        .status-toggle.offline {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .header-actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            background: var(--card);
            border: 1px solid var(--border);
            padding: 6px 10px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--muted-foreground);
        }
        .btn-icon:hover {
            background: var(--accent);
            border-color: var(--ring);
            color: var(--accent-foreground);
        }
        .chart-wrapper { height: 300px; position: relative; }
        .station-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .station-header h2 {
            font-size: 16px;
            color: var(--foreground);
            font-weight: 600;
        }
    </style>
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
                <div class="user-name"><?php echo htmlspecialchars($owner['company_name']); ?></div>
                <div class="user-email">owner@evcharge.com</div>
            </div>
            <div class="dropdown-body">
                <div class="dropdown-item" onclick="loadSection('profile')"><i class="fas fa-store" style="width:16px;"></i> Company Profile</div>
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
                <img src="<?php echo htmlspecialchars($profilePicPath); ?>" alt="Profile" class="profile-pic" style="border-color: #34C759;">
                <div class="profile-name"><?php echo htmlspecialchars($owner['company_name']); ?></div>
            </div>

            <!-- Navigation Buttons -->
            <div class="sidebar-nav">
                <button type="button" class="nav-btn<?php echo $page === 'overview' ? ' active' : ''; ?>" data-section="overview" onclick="loadSection('overview')">
                    <i class="fas fa-chart-pie"></i> <span>Overview</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'profile' ? ' active' : ''; ?>" data-section="profile" onclick="loadSection('profile')">
                    <i class="fas fa-store"></i> <span>Company Profile</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'financials' ? ' active' : ''; ?>" data-section="financials" onclick="loadSection('financials')">
                    <i class="fas fa-chart-bar"></i> <span>Financials</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'stations' ? ' active' : ''; ?>" data-section="stations" onclick="loadSection('stations')">
                    <i class="fas fa-charging-station"></i> <span>My Stations</span>
                </button>
                <button type="button" class="nav-btn<?php echo $page === 'bookings' ? ' active' : ''; ?>" data-section="bookings" onclick="loadSection('bookings')">
                    <i class="fas fa-receipt"></i> <span>Bookings</span>
                </button>
            </div>

            <!-- Logout Button -->
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
            <?php include "owner_sections/{$page}.php"; ?>
        </div>
    </div>
</div>

    <script src="../assets/js/modal.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let currentSection = '<?php echo $page; ?>';
        let map = null;
        let stationMarker = null;
        let revenueChart = null;
        let kwhChart = null;

        function loadSection(sectionName) {
            // Guard: do nothing if already on this section
            if (currentSection === sectionName) return;

            if (currentSection !== sectionName) {
                history.pushState(null, '', `?page=${sectionName}`);
            }

            currentSection = sectionName;
            const contentArea = document.getElementById('content-area');
            
            contentArea.innerHTML = `
                <div style="padding: 32px; text-align: center; color: #8E8E93;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                    <p>Loading ${sectionName}...</p>
                </div>
            `;

        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('active');
            // Reset styles
            btn.style.background = '';
            btn.style.boxShadow = '';
            btn.style.borderColor = '';
        });

        const activeBtn = document.querySelector(`.nav-btn[data-section="${sectionName}"]`);
        if (activeBtn) {
            activeBtn.classList.add('active');
            // Apply green theme active style to match driver page
            activeBtn.style.background = 'linear-gradient(135deg, #34C759 0%, #20c997 100%)';
            activeBtn.style.borderColor = '#34C759';
            activeBtn.style.boxShadow = '0 4px 12px rgba(52, 199, 89, 0.4)';
            activeBtn.style.transform = 'translateX(4px)';
        }

            fetch(`owner_sections/${sectionName}.php`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {
                    contentArea.innerHTML = html;
                    initializeSection(sectionName);
                })
                .catch(error => {
                    console.error('Failed to load section:', error);
                    contentArea.innerHTML = `
                        <div style="padding: 32px; text-align: center; color: #FF3B30;">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                            <p>Failed to load this section</p>
                            <button onclick="loadSection('${sectionName}')" style="margin-top: 16px; padding: 8px 16px; background: #34C759; color: white; border: none; border-radius: 8px; cursor: pointer;">
                                Try Again
                            </button>
                        </div>
                    `;
                });
        }

        function switchFinancialView(period) {
            document.querySelectorAll('[id^="view-"]').forEach(b => { b.className = 'btn btn-sm btn-secondary'; });
            document.getElementById('view-' + period).className = 'btn btn-sm btn-primary';

            const mockData = {
                days:  { rev: [1200, 980, 1500, 2100, 1800, 2400, 1600], fee: [360, 294, 450, 630, 540, 720, 480], kwh: [120, 98, 150, 210, 180, 240, 160] },
                months: { rev: [45000, 52000, 48000, 61000, 55000, 72000], fee: [13500, 15600, 14400, 18300, 16500, 21600], kwh: [4500, 5200, 4800, 6100, 5500, 7200] },
                years: { rev: [520000, 580000, 650000], fee: [156000, 174000, 195000], kwh: [52000, 58000, 65000] }
            };
            var data = mockData[period];
            if (!data) return;

            if (revenueChart) revenueChart.destroy();
            if (kwhChart) kwhChart.destroy();

            var revCtx = document.getElementById('revenueChart').getContext('2d');
            revenueChart = new Chart(revCtx, {
                type: 'bar',
                data: {
                    labels: data.rev.map(function(_, i) { return period === 'days' ? 'Day ' + (i+1) : period === 'months' ? 'Month ' + (i+1) : 'Year ' + (i+1); }),
                    datasets: [
                        { label: 'Gross Revenue', data: data.rev, backgroundColor: 'rgba(52, 199, 89, 0.6)', borderColor: '#34C759', borderWidth: 1 },
                        { label: 'Platform Fee', data: data.fee, backgroundColor: 'rgba(255, 149, 0, 0.6)', borderColor: '#FF9500', borderWidth: 1 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            var kwhCtx = document.getElementById('kwhChart').getContext('2d');
            kwhChart = new Chart(kwhCtx, {
                type: 'line',
                data: {
                    labels: data.kwh.map(function(_, i) { return period === 'days' ? 'Day ' + (i+1) : period === 'months' ? 'Month ' + (i+1) : 'Year ' + (i+1); }),
                    datasets: [{
                        label: 'kWh Consumed',
                        data: data.kwh,
                        backgroundColor: 'rgba(0, 122, 255, 0.1)',
                        borderColor: '#007AFF',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }

        function initializeSection(sectionName) {
            if (sectionName === 'stations') {
                setTimeout(initLocationPickerMap, 200);
            }
            if (sectionName === 'financials') {
                setTimeout(function() { switchFinancialView('months'); }, 50);
            }
        }

        // Run once on initial page load for the pre-rendered section
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { initializeSection(currentSection); });
        } else {
            initializeSection(currentSection);
        }

        function initLocationPickerMap() {
            const mapPicker = document.getElementById('map-picker');
            if (!mapPicker) return;

            const latInput = document.getElementById('lat-input');
            const lonInput = document.getElementById('lon-input');
            
            let startLat = parseFloat(latInput.value) || 27.7172;
            let startLon = parseFloat(lonInput.value) || 85.3240;

            map = L.map('map-picker', { scrollWheelZoom: false }).setView([startLat, startLon], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);

            map.on('click', function() { map.scrollWheelZoom.enable(); });
            document.addEventListener('click', function(e) { if (!e.target.closest('#map-picker')) { map.scrollWheelZoom.disable(); } });

            stationMarker = L.marker([startLat, startLon], {
                draggable: true
            }).addTo(map);

            stationMarker.on('dragend', function(e) {
                const position = stationMarker.getLatLng();
                latInput.value = position.lat.toFixed(6);
                lonInput.value = position.lng.toFixed(6);
                
                // Get location details via reverse geocoding
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${position.lat}&lon=${position.lng}`)
                    .then(r => r.json())
                    .then(data => {
                        const addrInput = document.getElementById('address-input');
                        const cityInput = document.getElementById('city-input');
                        if (addrInput) addrInput.value = data.display_name || '';
                        if (cityInput) cityInput.value = data.address.city || data.address.town || data.address.village || '';
                    });
            });

            map.on('click', function(e) {
                stationMarker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(6);
                lonInput.value = e.latlng.lng.toFixed(6);
                
                // Trigger geocoding
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
                    .then(r => r.json())
                    .then(data => {
                        const addrInput = document.getElementById('address-input');
                        const cityInput = document.getElementById('city-input');
                        if (addrInput) addrInput.value = data.display_name || '';
                        if (cityInput) cityInput.value = data.address.city || data.address.town || data.address.village || '';
                    });
            });
        }

        function logout() {
            window.location.href = '../logout.php';
        }

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

        // --- stations.php (station & charger management) ---
        let chargerCount = 0;

        function toggleStationView(viewId) {
            document.getElementById('list-view').style.display = 'none';
            document.getElementById('register-view').style.display = 'none';
            document.getElementById('chargers-management-view').style.display = 'none';
            document.getElementById(viewId).style.display = 'block';

            // Leaflet renders at the size its container was when initialized.
            // If the container was hidden (display:none), tiles only load partially.
            // Force a recalculation after the container layout is settled.
            if (viewId === 'register-view') {
                setTimeout(function() { if (map) map.invalidateSize(); }, 100);

                // Auto-add a default charger row so the form isn't empty
                if (document.querySelectorAll('.charger-config-row').length === 0) {
                    addChargerRow();
                }
            }
        }

        function addChargerRow() {
            chargerCount++;
            const container = document.getElementById('chargers-builder-container');
            const row = document.createElement('div');
            row.className = 'charger-config-row';
            row.id = `charger-row-${chargerCount}`;
            row.innerHTML = `
                <select class="sort-select" style="margin: 0; flex: 2;" required name="charger_type_${chargerCount}">
                    <option value="DC Fast">DC Fast (CCS2)</option>
                    <option value="AC 22kW">AC 22kW Type 2</option>
                    <option value="AC 11kW">AC 11kW Type 2</option>
                    <option value="AC 7.4kW">AC 7.4kW Type 2</option>
                    <option value="GB/T Fast DC">GB/T Fast DC</option>
                </select>
                <input type="number" class="location-input" style="flex: 1;" placeholder="Wattage (kW)" min="1" max="350" required name="charger_wattage_${chargerCount}" value="22">
                <button type="button" class="btn btn-danger" style="padding: 12px;" onclick="removeChargerRow(${chargerCount})">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(row);
        }

        function removeChargerRow(id) {
            const row = document.getElementById(`charger-row-${id}`);
            if (row) row.remove();
        }

        async function submitStation(event) {
            event.preventDefault();
            const name = document.getElementById('name').value;
            const description = document.getElementById('description').value;
            const latitude = document.getElementById('lat-input').value;
            const longitude = document.getElementById('lon-input').value;
            const address = document.getElementById('address-input').value;
            const city = document.getElementById('city-input').value;
            const chargers = [];
            const container = document.getElementById('chargers-builder-container');
            const rows = container.querySelectorAll('.charger-config-row');
            if (rows.length === 0) {
                showAlert('Please add at least one charger to this station.', 'error');
                return;
            }
            rows.forEach(row => {
                const selects = row.querySelectorAll('select');
                const inputs = row.querySelectorAll('input');
                chargers.push({ type: selects[0].value, wattage: inputs[0].value });
            });
            const data = { name, description, latitude, longitude, address, city, chargers };
            try {
                const response = await fetch('../api/stations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert('Station submitted successfully for admin approval.', 'success');
                    loadSection('stations');
                } else {
                    showAlert(result.message || 'Failed to submit station.', 'error');
                }
            } catch (e) {
                console.error('Error submitting station:', e);
                showAlert('Network error. Try again.', 'error');
            }
        }

        async function manageStationChargers(stationId, stationName) {
            document.getElementById('manage-chargers-title').textContent = `🔌 Chargers for "${stationName}"`;
            toggleStationView('chargers-management-view');
            const body = document.getElementById('chargers-table-body');
            body.innerHTML = `<tr><td colspan="5" style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>`;
            try {
                const response = await fetch(`../api/stations.php?id=${stationId}`);
                const result = await response.json();
                if (result.status === 'success') {
                    body.innerHTML = '';
                    const chargers = result.data.chargers || [];
                    if (chargers.length === 0) {
                        body.innerHTML = `<tr><td colspan="5" style="text-align:center;">No chargers added yet.</td></tr>`;
                        return;
                    }
                    chargers.forEach(charger => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>#<strong>${charger.charger_number}</strong></td>
                            <td>${htmlspecialchars(charger.charger_type)}</td>
                            <td>${charger.wattage_kw} kW</td>
                            <td><span class="badge ${getChargerBadge(charger.status)}">${charger.status}</span></td>
                            <td>
                                <select onchange="updateChargerStatus(${charger.id}, this.value, ${stationId}, '${stationName}')" class="sort-select" style="margin: 0; padding: 4px 8px; font-size: 12px; width: auto; height: auto;">
                                    <option value="available" ${charger.status === 'available' ? 'selected' : ''}>Available</option>
                                    <option value="maintenance" ${charger.status === 'maintenance' ? 'selected' : ''}>Maintenance</option>
                                    <option value="offline" ${charger.status === 'offline' ? 'selected' : ''}>Offline</option>
                                    <option value="charging" ${charger.status === 'charging' ? 'selected' : ''} disabled>Charging (In Session)</option>
                                </select>
                            </td>
                        `;
                        body.appendChild(row);
                    });
                } else {
                    body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger);">${result.message}</td></tr>`;
                }
            } catch (e) {
                body.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger);">Error loading details.</td></tr>`;
            }
        }

        function getChargerBadge(status) {
            if (status === 'available') return 'badge-success';
            if (status === 'charging') return 'badge-warning';
            if (status === 'maintenance') return 'badge-info';
            return 'badge-danger';
        }

        async function updateChargerStatus(chargerId, newStatus, stationId, stationName) {
            try {
                const response = await fetch(`../api/stations.php?action=update_charger_status`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ charger_id: chargerId, status: newStatus })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    manageStationChargers(stationId, stationName);
                } else {
                    showAlert(result.message || 'Failed to update status.', 'error');
                }
            } catch (e) {
                console.error(e);
                showAlert('Error updating charger status.', 'error');
            }
        }

        function deleteStation(id) {
            showConfirm('Are you absolutely sure you want to delete this station? All charger slots and related bookings will be deleted.', function() {
                doDeleteStation(id);
            }, { confirmLabel: 'Delete Station', confirmClass: 'btn-danger' });
        }

        async function doDeleteStation(id) {
            try {
                const response = await fetch(`../api/stations.php?id=${id}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert('Station deleted successfully.', 'success');
                    loadSection('stations');
                } else {
                    showAlert(result.message || 'Failed to delete.', 'error');
                }
            } catch (e) {
                showAlert('Connection error.', 'error');
            }
        }

        function htmlspecialchars(str) {
            var amp = '&' + 'amp;';
            var lt = '&' + 'lt;';
            var gt = '&' + 'gt;';
            var quot = '&' + 'quot;';
            var apos = '&' + '#039;';
            return str.replace(/&/g, amp).replace(/</g, lt).replace(/>/g, gt).replace(/"/g, quot).replace(/'/g, apos);
        }

        // --- bookings.php (session start/stop) ---
        function updateSession(bookingId, action) {
            if (action === 'start_session') {
                // Themed modal for battery % at session start
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                const box = document.createElement('div');
                box.className = 'modal-box';
                box.style.textAlign = 'left';

                box.innerHTML = `
                    <div style="margin-bottom:20px;">
                        <h3 style="margin-bottom:4px;">🔌 Start Charging Session</h3>
                        <p style="color:var(--gray); font-size:13px;">Enter the vehicle's current battery percentage to calculate the estimated charge time and cost.</p>
                    </div>
                    <div style="margin-bottom:24px;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">Current Battery %</label>
                        <input type="number" id="start-battery-input" class="location-input" style="width:100%;" min="1" max="100" placeholder="Enter battery % (1–100)" value="">
                    </div>
                    <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid var(--border); padding-top:16px;">
                        <button class="btn btn-secondary" id="start-cancel-btn">Cancel</button>
                        <button class="btn btn-primary" id="start-confirm-btn">Start Session</button>
                    </div>
                `;

                overlay.appendChild(box);
                document.body.appendChild(overlay);
                requestAnimationFrame(() => overlay.classList.add('show'));

                const close = () => { overlay.classList.remove('show'); setTimeout(() => overlay.remove(), 200); };
                overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
                box.querySelector('#start-cancel-btn').onclick = close;

                box.querySelector('#start-confirm-btn').onclick = function() {
                    const batteryPct = parseInt(box.querySelector('#start-battery-input').value);
                    if (!batteryPct || batteryPct < 1 || batteryPct > 100) {
                        showAlert('Please enter a valid battery percentage (1–100).', 'error');
                        return;
                    }
                    close();
                    doUpdateSession(bookingId, 'start_session', batteryPct);
                };
            } else {
                showConfirm('Complete charging session and generate billing receipt?', function() {
                    doUpdateSession(bookingId, 'complete_session');
                });
            }
        }

        async function doUpdateSession(bookingId, action, batteryPercent) {
            try {
                const body = { action: action };
                if (batteryPercent !== undefined) {
                    body.battery_percent = batteryPercent;
                }
                const response = await fetch(`../api/bookings.php?id=${bookingId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await response.json();
                if (result.status === 'success') {
                    showAlert(result.message, 'success');
                    loadSection('bookings');
                } else {
                    showAlert(result.message || 'Operation failed.', 'error');
                }
            } catch (e) {
                console.error(e);
                showAlert('Error updating session. Try again.', 'error');
            }
        }

        // --- profile.php (owner profile form) ---
        async function saveProfile(event) {
            event.preventDefault();
            const form = document.getElementById('owner-profile-form');
            const formData = new FormData(form);
            try {
                const response = await fetch('owner_sections/profile.php', {
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
