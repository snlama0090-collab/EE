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
            <div class="stations-section visible">
                <?php foreach ($stations as $station): 
                    $approval = $station['approval_status'];
                    $badge = 'badge-warning';
                    if ($approval === 'approved') $badge = 'badge-success';
                    elseif ($approval === 'rejected') $badge = 'badge-danger';
                ?>
                <div class="station-card">
                    <div class="station-info">
                        <div class="station-name"><?php echo htmlspecialchars($station['name']); ?></div>
                        <div class="station-details">
                            <span class="detail-item"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($station['city']); ?></span>
                            <span class="detail-item"><i class="fas fa-road"></i> <?php echo htmlspecialchars($station['address']); ?></span>
                            <span class="detail-item"><i class="fas fa-plug"></i> <span class="available-chargers"><?php echo $station['available_chargers']; ?></span> / <?php echo $station['charger_count']; ?> Available</span>
                        </div>
                        <?php if ($station['rejection_reason']): ?>
                            <div style="font-size: 11px; color: var(--destructive); margin-top: 4px;">
                                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($station['rejection_reason']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <span class="badge <?php echo $badge; ?>"><?php echo $approval; ?></span>
                        <button class="btn btn-primary btn-sm" onclick="manageStationChargers(<?php echo $station['id']; ?>, '<?php echo addslashes($station['name']); ?>')">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="deleteStation(<?php echo $station['id']; ?>)">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
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

