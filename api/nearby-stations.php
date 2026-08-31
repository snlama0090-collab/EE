<?php
/**
 * Public nearby-stations endpoint for the landing page.
 * No auth required. Returns only non-sensitive station fields
 * (name, city/address, lat/lng, availability, rating) — never
 * owner details, internal admin fields, or revenue.
 *
 * GET /api/nearby-stations.php?latitude=...&longitude=...&radius=10  → nearby, sorted by distance
 * GET /api/nearby-stations.php                                      → all approved+active stations
 */

header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Location.php';

// Counts/lists don't change every second — allow a short public cache.
header('Cache-Control: public, max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = getDB();

    $lat = isset($_GET['latitude'])  ? floatval($_GET['latitude'])  : null;
    $lon = isset($_GET['longitude']) ? floatval($_GET['longitude']) : null;
    $radius = isset($_GET['radius']) ? floatval($_GET['radius']) : DEFAULT_SEARCH_RADIUS_KM;

    $sql = "
        SELECT s.id, s.name, s.address, s.city,
               s.latitude, s.longitude, s.num_chargers,
               s.average_rating,
               COUNT(c.id) AS charger_count,
               SUM(CASE WHEN c.status = 'available' AND (
                   SELECT COUNT(*) FROM bookings b WHERE b.charger_id = c.id AND b.status IN ('booked', 'charging')
               ) = 0 THEN 1
               WHEN c.status = 'charging' AND (
                   SELECT COUNT(*) FROM bookings b WHERE b.charger_id = c.id AND b.status IN ('booked', 'charging')
               ) = 1 THEN 1
               ELSE 0 END) AS available_chargers,
               GROUP_CONCAT(DISTINCT CONCAT(c.charger_type, ' (', c.wattage_kw, 'kW)')
                            ORDER BY c.wattage_kw DESC SEPARATOR ', ') AS charger_details,
               (SELECT COUNT(*) FROM ratings_reviews rr WHERE rr.station_id = s.id AND rr.is_deleted = FALSE) AS review_count
        FROM stations s
        LEFT JOIN chargers c ON s.id = c.station_id
        WHERE s.approval_status = 'approved' AND s.is_active = TRUE
        GROUP BY s.id
    ";

    $stmt = $db->prepare($sql);

    if ($lat !== null && $lon !== null) {
        // Pre-filter with a SQL bounding box, then post-filter with precise Haversine
        $latOffset = $radius / 111.0;
        $lonOffset = $radius / (111.0 * cos(deg2rad($lat)));
        $stmt = $db->prepare($sql . " HAVING latitude BETWEEN :min_lat AND :max_lat AND longitude BETWEEN :min_lng AND :max_lng");
        $stmt->execute([
            'min_lat' => $lat - $latOffset,
            'max_lat' => $lat + $latOffset,
            'min_lng' => $lon - $lonOffset,
            'max_lng' => $lon + $lonOffset,
        ]);
        $stations = $stmt->fetchAll();

        $stations = Location::getNearbyLocations($stations, $lat, $lon, $radius);
    } else {
        $stmt->execute();
        $stations = $stmt->fetchAll();
    }

    echo json_encode(['status' => 'success', 'data' => $stations]);

} catch (Throwable $e) {
    error_log('Nearby stations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load stations.']);
}