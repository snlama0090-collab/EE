<?php
// This file outputs only the HTML fragment for the "Find Stations" section
// No full page structure - just the content that goes inside #content-area

require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

// Require driver login
Auth::requireUserType('driver');

$db = getDB();

// Get all available stations with charger details
// available_chargers = chargers that are bookable (available+no active bookings, or charging+has single active=bookable next-in-line)
$stmt = $db->prepare("
    SELECT 
        s.*,
        COUNT(c.id) as charger_count,
        SUM(CASE WHEN c.status = 'available' AND (
            SELECT COUNT(*) FROM bookings b WHERE b.charger_id = c.id AND b.status IN ('booked', 'charging')
        ) = 0 THEN 1
        WHEN c.status = 'charging' AND (
            SELECT COUNT(*) FROM bookings b WHERE b.charger_id = c.id AND b.status IN ('booked', 'charging')
        ) = 1 THEN 1
        ELSE 0 END) as available_chargers,
        GROUP_CONCAT(DISTINCT c.charger_type ORDER BY c.charger_type) as charger_types,
        GROUP_CONCAT(DISTINCT CONCAT(c.charger_type, ' (', c.wattage_kw, 'kW)') ORDER BY c.wattage_kw DESC SEPARATOR ', ') as charger_details,
        5.0 as average_rating
    FROM stations s
    LEFT JOIN chargers c ON s.id = c.station_id
    WHERE s.approval_status = 'approved'
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT 20
");
$stmt->execute();
$stations = $stmt->fetchAll();
?>

<!-- SEARCH & LOCATION BAR -->
<div class="search-section">
    <form id="search-form" onsubmit="searchStations(); return false;" style="display: contents;">
        <input type="text" id="location-input" class="location-input" placeholder="Name of location" value="">
        <button type="submit" class="search-btn">
            <i class="fas fa-search"></i>
        </button>
    </form>
    <button class="detect-btn" onclick="detectLocation()">
        <i class="fas fa-location-crosshairs"></i> Detect location
    </button>
</div>

<!-- MAP AREA -->
<div class="map-area" id="map">
    <div class="map-placeholder">
        <i class="fas fa-map"></i>
        <p>Map will display here</p>
    </div>
</div>

<!-- SORT BAR -->
<div class="sort-section">
    <select id="range-filter" class="sort-select" onchange="filterStations()">
        <option value="">All Ranges</option>
        <option value="1">Within 1 km</option>
        <option value="2">Within 2 km</option>
        <option value="5">Within 5 km</option>
        <option value="10">Within 10 km</option>
    </select>
    <select id="charger-filter" class="sort-select" onchange="filterStations()">
        <option value="">All Charger Types</option>
        <option value="Fast DC">Fast DC</option>
        <option value="Level 2 AC">Level 2 AC</option>
        <option value="Level 3">Level 3</option>
        <option value="Standard">Standard</option>
    </select>
    <select id="sort-filter" class="sort-select" onchange="sortStations(this.value)">
        <option value="recent">Recent</option>
        <option value="rating">Top Rated</option>
        <option value="distance">Closest</option>
        <option value="chargers">Most Chargers</option>
    </select>
</div>

<!-- STATIONS LIST -->
<div class="stations-section" id="stations-section" style="display: none;">
    <?php foreach ($stations as $station): ?>
    <div class="station-card" 
         data-station-id="<?php echo $station['id']; ?>" 
         data-distance="0" 
         data-latitude="<?php echo $station['latitude']; ?>"
         data-longitude="<?php echo $station['longitude']; ?>"
         data-charger-type="<?php echo htmlspecialchars($station['charger_types'] ?? 'AC'); ?>"
         data-available="<?php echo $station['available_chargers'] ?? 0; ?>"
         data-charger-count="<?php echo $station['charger_count']; ?>">
        <div class="station-info">
            <div class="station-name"><?php echo htmlspecialchars($station['name']); ?></div>
            <div class="station-city"><?php echo htmlspecialchars($station['city'] ?? 'Unknown'); ?></div>
            
            <div class="station-details">
                <span class="detail-item">⭐ <?php echo round($station['average_rating'] ?? 0, 1); ?>/5</span>
                <span class="detail-item">📍 <span class="station-distance">0</span> km</span>
            </div>
            
            <div class="charger-info">
                <div class="charger-count">
                    <i class="fas fa-plug"></i> 
                    <strong><?php echo $station['charger_count']; ?> Chargers</strong> 
                    (<span class="available-chargers"><?php echo $station['available_chargers'] ?? 0; ?></span> available)
                </div>
                <div class="charger-types">
                    <?php 
                        $chargerDetails = explode(', ', $station['charger_details'] ?? 'Standard');
                        foreach ($chargerDetails as $charger): 
                            if (!empty($charger)):
                    ?>
                        <span class="charger-badge">⚡ <?php echo htmlspecialchars($charger); ?></span>
                    <?php 
                            endif;
                        endforeach; 
                    ?>
                </div>
            </div>
        </div>
        <button class="book-btn" onclick="bookStation(<?php echo $station['id']; ?>)">
            Book
        </button>
    </div>
    <?php endforeach; ?>
</div>
