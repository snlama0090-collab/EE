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
            fetch(`../../api/stations.php?action=approve&id=${stationId}`, { method: 'POST' })
                .then(r => r.json()).then(data => {
                    if (data.status === 'success') loadSection(currentSection);
                });
        });
    }

    function rejectStation(stationId) {
        const reason = prompt('Reason for rejection:');
        if (reason) {
            fetch(`../../api/stations.php?action=reject&id=${stationId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason })
            }).then(r => r.json()).then(data => {
                if (data.status === 'success') loadSection(currentSection);
            });
        }
    }

    function logout() {
        window.location.href = '../logout.php';
    }
</script>
</body>
</html>