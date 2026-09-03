<?php
/**
 * Public stats endpoint — aggregate counts for the landing page.
 * No auth required. Returns ONLY aggregate integers, never row-level data.
 *
 * GET /api/stats.php
 * Response: { "status": "success", "data": { "stations": 324, "drivers": 1234, "owners": 87 } }
 */

header('Content-Type: application/json');
require_once '../app/config/config.php';

// Counts don't change every second — allow a short public cache.
header('Cache-Control: public, max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = getDB();

    // Active stations: approved by admin AND not deactivated
    $stmt = $db->query("SELECT COUNT(*) AS count FROM stations WHERE is_active = TRUE AND approval_status = 'approved' AND deactivated_at IS NULL");
    $stations = (int) $stmt->fetch()['count'];

    // Registered EV drivers: active accounts
    $stmt = $db->query("SELECT COUNT(*) AS count FROM users WHERE status = 'active'");
    $drivers = (int) $stmt->fetch()['count'];

    // Station owners: active accounts AND approved by admin
    $stmt = $db->query("SELECT COUNT(*) AS count FROM owners WHERE status = 'active' AND approval_status = 'approved'");
    $owners = (int) $stmt->fetch()['count'];

    // Total published reviews (public-facing count)
    $stmt = $db->query("SELECT COUNT(*) AS count FROM ratings_reviews WHERE is_deleted = FALSE");
    $reviews = (int) $stmt->fetch()['count'];

    echo json_encode([
        'status' => 'success',
        'data' => [
            'stations' => $stations,
            'drivers'  => $drivers,
            'owners'   => $owners,
            'reviews'  => $reviews,
        ]
    ]);

} catch (Throwable $e) {
    error_log('Stats API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load stats.']);
}