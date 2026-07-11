<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Get owner's stations
$stmt = $db->prepare("
    SELECT s.*, 
           COUNT(c.id) as charger_count,
           SUM(CASE WHEN c.status = 'available' THEN 1 ELSE 0 END) as available_chargers
    FROM stations s
    LEFT JOIN chargers c ON s.id = c.station_id
    WHERE s.owner_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$stmt->execute([$user_id]);
$stations = $stmt->fetchAll();
?>

<div class="stations-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2>🔌 My Charging Stations</h2>
        <button class="btn btn-primary" onclick="toggleStationView('register-view')">
            <i class="fas fa-plus"></i> Register New Station
        </button>
    </div>

    <!-- STATIONS LIST VIEW -->
    <div id="list-view" class="dashboard-section-card">
        <?php if (count($stations) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Station Name</th>
                            <th>City / Address</th>
                            <th>Chargers</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stations as $station): 
                            $approval = $station['approval_status'];
                            $badge = 'badge-warning';
                            if ($approval === 'approved') $badge = 'badge-success';
                            elseif ($approval === 'rejected') $badge = 'badge-danger';
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($station['name']); ?></strong>
                                <?php if ($station['rejection_reason']): ?>
                                    <div style="font-size: 11px; color: var(--danger); margin-top: 4px;">
                                        Reason: <?php echo htmlspecialchars($station['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($station['city']); ?></div>
                                <div style="font-size: 11px; color: var(--gray);"><?php echo htmlspecialchars($station['address']); ?></div>
                            </td>
                            <td>
                                <strong><?php echo $station['available_chargers']; ?></strong> / <?php echo $station['charger_count']; ?> Available
                            </td>
                            <td>
                                <span class="badge <?php echo $badge; ?>"><?php echo $approval; ?></span>
                            </td>
                            <td>
                                <div class="header-actions">
                                    <button class="btn-icon" onclick="manageStationChargers(<?php echo $station['id']; ?>, '<?php echo addslashes($station['name']); ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-icon" onclick="deleteStation(<?php echo $station['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--gray);">
                <i class="fas fa-charging-station" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                <h3>No Stations Registered Yet</h3>
                <p>Register your first station to start hosting EV drivers.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- CHARGERS MODAL / DETAILED PANEL (HIDDEN BY DEFAULT) -->
    <div id="chargers-management-view" class="dashboard-section-card" style="display: none;">
        <div class="station-header">
            <h3 id="manage-chargers-title">Manage Chargers</h3>
            <button class="btn btn-secondary btn-sm" onclick="toggleStationView('list-view')">Back to List</button>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Charger #</th>
                        <th>Type</th>
                        <th>Wattage</th>
                        <th>Current Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="chargers-table-body">
                    <!-- Dynamic Rows -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- REGISTER STATION VIEW (HIDDEN BY DEFAULT) -->
    <div id="register-view" class="dashboard-section-card" style="display: none;">
        <div class="station-header">
            <h3>Register New Charging Station</h3>
            <button class="btn btn-secondary btn-sm" onclick="toggleStationView('list-view')">Cancel</button>
        </div>

        <form id="station-register-form" onsubmit="submitStation(event)">
            <div class="form-grid">
                <div class="form-control-group form-full">
                    <label for="name">Station Name *</label>
                    <input type="text" id="name" name="name" required placeholder="e.g. Kathmandu Central Hub">
                </div>
                
                <div class="form-control-group form-full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" placeholder="Tell drivers about amenities, location tips, etc." style="width:100%; padding:12px; border:1px solid var(--border); border-radius:8px;"></textarea>
                </div>

                <!-- Leaflet Location Picker Map -->
                <div class="form-control-group form-full">
                    <label>Position Station on Map * (Drag/Click to select position)</label>
                    <div id="map-picker" style="height: 250px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 8px;"></div>
                </div>

                <div class="form-control-group">
                    <label for="lat-input">Latitude *</label>
                    <input type="text" id="lat-input" name="latitude" required readonly value="27.7172">
                </div>
                <div class="form-control-group">
                    <label for="lon-input">Longitude *</label>
                    <input type="text" id="lon-input" name="longitude" required readonly value="85.3240">
                </div>

                <div class="form-control-group form-full">
                    <label for="address-input">Street Address *</label>
                    <input type="text" id="address-input" name="address" required placeholder="Street address detected from map">
                </div>

                <div class="form-control-group form-full">
                    <label for="city-input">City *</label>
                    <input type="text" id="city-input" name="city" required placeholder="City name">
                </div>
            </div>

            <!-- Chargers Builder -->
            <div style="margin: 24px 0; border-top: 1px solid var(--border); padding-top: 16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h4>🔌 Chargers Configurator</h4>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addChargerRow()">
                        <i class="fas fa-plus"></i> Add Charger
                    </button>
                </div>
                <div id="chargers-builder-container">
                    <!-- Charger rows appear here -->
                </div>
            </div>

            <button type="submit" class="btn-submit">Submit Station for Approval</button>
        </form>
    </div>
</div>

<script>
    let chargerCount = 0;

    function toggleStationView(viewId) {
        document.getElementById('list-view').style.display = 'none';
        document.getElementById('register-view').style.display = 'none';
        document.getElementById('chargers-management-view').style.display = 'none';
        
        document.getElementById(viewId).style.display = 'block';
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

        // Gather chargers
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
            chargers.push({
                type: selects[0].value,
                wattage: inputs[0].value
            });
        });

        const data = {
            name, description, latitude, longitude, address, city, chargers
        };

        try {
            const response = await fetch('../../api/stations.php', {
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
            const response = await fetch(`../../api/stations.php?id=${stationId}`);
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
                    
                    let availableActive = charger.status === 'available' ? 'active' : '';
                    let maintenanceActive = charger.status === 'maintenance' ? 'active' : '';
                    let offlineActive = charger.status === 'offline' ? 'active' : '';
                    let chargingActive = charger.status === 'charging' ? 'active' : '';

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
            const response = await fetch(`../../api/stations.php?action=update_charger_status`, {
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
                const response = await fetch(`../../api/stations.php?id=${id}`, {
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
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // Add first charger row by default on load
    addChargerRow();
</script>
