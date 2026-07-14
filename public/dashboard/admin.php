<?php
require_once '../../app/config/config.php';
require_once '../../app/helpers/Auth.php';

Auth::requireUserType('admin');

// Server-side initial page — no flicker
$allowed = ['overview', 'stations', 'users', 'reviews', 'reports', 'settings'];
$page = in_array($_GET['page'] ?? '', $allowed) ? $_GET['page'] : 'overview';

$db = getDB();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EV Charging Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        /* Admin-specific overrides — shared layout comes from dashboard.css */
        .nav-btn.active {
            background: linear-gradient(135deg, #FF6B6B 0%, #FF8E72 100%);
            border-color: #FF6B6B;
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }
    </style>
    <script src="../assets/js/modal.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
<div class="dashboard-container">
    <!-- SIDEBAR (same structure as driver/owner dashboards) -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-profile">
            <div class="profile-pic" style="display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.2); font-size:32px; color:white;">🛡️</div>
            <div class="profile-name">Admin Panel</div>
        </div>

        <div class="sidebar-nav">
            <button class="nav-btn<?php echo $page === 'overview' ? ' active' : ''; ?>" data-section="overview" onclick="loadSection('overview')">
                <i class="fas fa-chart-pie"></i> Overview
            </button>
            <button class="nav-btn<?php echo $page === 'stations' ? ' active' : ''; ?>" data-section="stations" onclick="loadSection('stations')">
                <i class="fas fa-charging-station"></i> Stations
            </button>
            <button class="nav-btn<?php echo $page === 'users' ? ' active' : ''; ?>" data-section="users" onclick="loadSection('users')">
                <i class="fas fa-users"></i> Users
            </button>
            <button class="nav-btn<?php echo $page === 'reviews' ? ' active' : ''; ?>" data-section="reviews" onclick="loadSection('reviews')">
                <i class="fas fa-star"></i> Reviews
            </button>
            <button class="nav-btn<?php echo $page === 'reports' ? ' active' : ''; ?>" data-section="reports" onclick="loadSection('reports')">
                <i class="fas fa-chart-bar"></i> Reports
            </button>
            <button class="nav-btn<?php echo $page === 'settings' ? ' active' : ''; ?>" data-section="settings" onclick="loadSection('settings')">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>

        <div class="sidebar-logout">
            <button onclick="logout()" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content" id="content-area">
        <?php include "admin_sections/{$page}.php"; ?>
    </div>
</div>

<script>
    let currentSection = '<?php echo $page; ?>';

    function loadSection(sectionName) {
        if (currentSection !== sectionName) {
            history.pushState(null, '', `?page=${sectionName}`);
        }

        currentSection = sectionName;
        const contentArea = document.getElementById('content-area');

        // Show loading
        contentArea.innerHTML = `
            <div style="padding: 32px; text-align: center; color: #8E8E93;">
                <i class="fas fa-spinner fa-spin" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                <p>Loading ${sectionName}...</p>
            </div>
        `;

        // Update nav
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.style.background = '';
            btn.style.boxShadow = '';
            btn.style.borderColor = '';
            btn.style.transform = '';
        });

        const activeBtn = document.querySelector(`.nav-btn[data-section="${sectionName}"]`);
        if (activeBtn) {
            activeBtn.classList.add('active');
        }

        // Fetch section content
        fetch(`admin_sections/${sectionName}.php`)
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.text();
            })
            .then(html => {
                contentArea.innerHTML = html;
            })
            .catch(error => {
                console.error('Failed to load section:', error);
                contentArea.innerHTML = `
                    <div style="padding: 32px; text-align: center; color: #FF3B30;">
                        <i class="fas fa-exclamation-circle" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                        <p>Failed to load this section</p>
                        <button onclick="loadSection('${sectionName}')" style="margin-top: 16px; padding: 8px 16px; background: #FF6B6B; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Try Again
                        </button>
                    </div>
                `;
            });
    }

    function approveStation(stationId) {
        showConfirm('Approve this station?', function() {
            fetch(`../api/stations.php?action=approve&id=${stationId}`, { method: 'POST' })
                .then(r => r.json()).then(data => {
                    if (data.status === 'success') loadSection(currentSection);
                });
        });
    }

    function rejectStation(stationId) {
        const reason = prompt('Reason for rejection:');
        if (reason) {
            fetch(`../api/stations.php?action=reject&id=${stationId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason })
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') loadSection(currentSection);
            });
        }
    }

    let detailMap = null;
    let detailStationId = null;

    function closeStationDetail() {
        document.getElementById('station-detail-modal').style.display = 'none';
        if (detailMap) { detailMap.remove(); detailMap = null; }
    }

    function viewStationDetails(stationId) {
        detailStationId = stationId;
        document.getElementById('station-detail-modal').style.display = 'flex';
        document.getElementById('station-detail-actions').style.display = 'none';

        fetch(`../api/stations.php?id=${stationId}`)
            .then(r => r.json())
            .then(result => {
                if (result.status !== 'success') throw new Error(result.message || 'Failed');
                const s = result.data;
                const content = document.getElementById('station-detail-content');

                let chargerRows = '';
                (s.chargers || []).forEach(c => {
                    const badge = c.status === 'available' ? 'badge-success' : c.status === 'maintenance' ? 'badge-info' : c.status === 'charging' ? 'badge-warning' : 'badge-danger';
                    chargerRows += `<tr><td>#${c.charger_number}</td><td>${c.charger_type}</td><td>${c.wattage_kw} kW</td><td><span class="badge ${badge}">${c.status}</span></td></tr>`;
                });

                content.innerHTML = `
                    <h2 style="margin-bottom:8px;">🔌 ${s.name}</h2>
                    <p style="color:var(--gray); margin-bottom:16px;">${s.description || ''}</p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; font-size:14px;">
                        <div><strong>Owner:</strong> ${s.owner_company || 'N/A'}</div>
                        <div><strong>Submitted:</strong> ${new Date(s.created_at).toLocaleDateString()}</div>
                        <div><strong>Address:</strong> ${s.address || ''}, ${s.city || ''}</div>
                        <div><strong>Status:</strong> <span class="badge badge-warning">${s.approval_status}</span></div>
                    </div>
                    <div id="detail-map" style="height:200px; border-radius:10px; border:1px solid var(--border); margin-bottom:16px;"></div>
                    <h4 style="margin-bottom:8px;">🔌 Chargers</h4>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>#</th><th>Type</th><th>Wattage</th><th>Status</th></tr></thead>
                            <tbody>${chargerRows || '<tr><td colspan="4" style="text-align:center;color:var(--gray);">No chargers</td></tr>'}</tbody>
                        </table>
                    </div>
                `;

                // Init Leaflet on the detail map container
                setTimeout(function() {
                    if (detailMap) detailMap.remove();
                    const lat = parseFloat(s.latitude) || 27.7172;
                    const lng = parseFloat(s.longitude) || 85.3240;
                    detailMap = L.map('detail-map', { scrollWheelZoom: false }).setView([lat, lng], 14);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(detailMap);
                    L.marker([lat, lng]).addTo(detailMap);
                    detailMap.invalidateSize();
                    detailMap.on('click', function() { detailMap.scrollWheelZoom.enable(); });
                    document.addEventListener('click', function(e) { if (!e.target.closest('#detail-map')) { if (detailMap) detailMap.scrollWheelZoom.disable(); } });
                }, 150);

                document.getElementById('station-detail-actions').style.display = 'flex';
                document.getElementById('modal-approve-btn').onclick = function() { doModalApprove(s.id); };
                document.getElementById('modal-reject-btn').onclick = function() { doModalReject(s.id); };
            })
            .catch(function() {
                document.getElementById('station-detail-content').innerHTML = `
                    <div style="text-align:center;padding:32px;color:#FF3B30;">
                        <i class="fas fa-exclamation-circle" style="font-size:48px;display:block;margin-bottom:16px;"></i>
                        <p>Failed to load station details.</p>
                    </div>`;
            });
    }

    function doModalApprove(id) {
        showConfirm('Approve this station?', function() {
            fetch(`../api/stations.php?action=approve&id=${id || detailStationId}`, { method: 'POST' })
                .then(r => r.json()).then(function(data) {
                    if (data.status === 'success') { closeStationDetail(); loadSection(currentSection); }
                });
        });
    }

    function doModalReject(id) {
        var sid = id || detailStationId;
        showConfirm('Reject this station? A rejection reason is required.', function() {
            var reason = prompt('Reason for rejection:');
            if (reason) {
                fetch(`../api/stations.php?action=reject&id=${sid}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reason: reason })
                }).then(r => r.json()).then(function(data) {
                    if (data.status === 'success') { closeStationDetail(); loadSection(currentSection); }
                });
            }
        }, { confirmLabel: 'Reject Station', confirmClass: 'btn-danger' });
    }

    function logout() {
        window.location.href = '../logout.php';
    }
</script>
<!-- Station Detail Modal (hidden by default) -->
<div id="station-detail-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:16px; width:90%; max-width:720px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 40px rgba(0,0,0,0.3); padding:28px; position:relative;">
        <button onclick="closeStationDetail()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:24px; cursor:pointer; color:#8E8E93; line-height:1;">&times;</button>
        <div id="station-detail-content">
            <div style="text-align:center; padding:32px; color:#8E8E93;"><i class="fas fa-spinner fa-spin" style="font-size:48px; display:block; margin-bottom:16px;"></i><p>Loading details...</p></div>
        </div>
        <div id="station-detail-actions" style="display:none; margin-top:20px; padding-top:16px; border-top:1px solid var(--border); gap:12px; justify-content:flex-end;">
            <button class="btn btn-sm btn-secondary" onclick="closeStationDetail()">Cancel</button>
            <button class="btn btn-sm btn-primary" id="modal-approve-btn">Approve</button>
            <button class="btn btn-sm btn-danger" id="modal-reject-btn">Reject</button>
        </div>
    </div>
</div>

</body>
</html>
