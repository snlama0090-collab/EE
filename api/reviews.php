<?php
/**
 * Reviews API — Phase 1 (create + station review list).
 *
 * CSRF DESIGN DECISION (2026-08-29): Csrf::validate() guards ONLY
 * action=create (POST, state-changing). The GET station-review list is
 * read-only and deliberately token-free — same reasoning as the profile
 * fragment render (§17 incident 2026-08-29: an unconditional gate 403'd
 * GET fragment loads for ~1h). Do NOT "fix" a missing GET token check
 * later without revisiting that incident entry first.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/Auth.php';
require_once __DIR__ . '/../app/helpers/Csrf.php';

Auth::requireUserType('driver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Read-only list for the station-detail modal (driver-facing, Phase 1).
    // Deliberately NOT CSRF-gated — see the design decision above.
    $station_id = intval($_GET['station_id'] ?? 0);
    if ($station_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'station_id required']);
        exit;
    }
    $db = getDB();
    $stmt = $db->prepare("
        SELECT rr.id, rr.rating, rr.comment, rr.created_at, u.name AS user_name
        FROM ratings_reviews rr
        JOIN users u ON rr.user_id = u.id
        WHERE rr.station_id = ? AND rr.is_deleted = FALSE
        ORDER BY rr.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $avg = $db->prepare("SELECT average_rating FROM stations WHERE id = ?");
    $avg->execute([$station_id]);
    echo json_encode([
        'status' => 'success',
        'data' => ['reviews' => $stmt->fetchAll(), 'average_rating' => (float) ($avg->fetch()['average_rating'] ?? 0)],
    ]);
    exit;
}

// ── POST: create a review for a finished session ──
Csrf::validate();

$input = json_decode(file_get_contents('php://input'), true);
$booking_id = intval($input['booking_id'] ?? 0);
$rating = intval($input['rating'] ?? 0);
$comment = trim($input['comment'] ?? '');

if ($rating < 1 || $rating > 5) {
    echo json_encode(['status' => 'error', 'message' => 'Rating must be between 1 and 5 stars.']);
    exit;
}
if (mb_strlen($comment) > 1000) {
    echo json_encode(['status' => 'error', 'message' => 'Review must be 1000 characters or fewer.']);
    exit;
}
if ($comment === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please write a short review.']);
    exit;
}

$user_id = Auth::getCurrentUserId();
$db = getDB();

try {
    $db->beginTransaction();

    // Eligibility: this driver's booking, finished charging (completed or
    // stopped — both billed, neither cancelled), mapped to its station.
    $stmt = $db->prepare("
        SELECT b.id, c.station_id
        FROM bookings b
        JOIN chargers c ON b.charger_id = c.id
        WHERE b.id = ? AND b.user_id = ? AND b.status IN ('completed', 'stopped')
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Booking not found, not finished, or not yours.']);
        exit;
    }

    // One review per booking — friendly pre-check; the
    // UNIQUE (user_id, station_id, booking_id) constraint is the hard backstop.
    $dup = $db->prepare("SELECT id FROM ratings_reviews WHERE booking_id = ?");
    $dup->execute([$booking_id]);
    if ($dup->fetch()) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'You have already reviewed this session.']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO ratings_reviews (user_id, station_id, booking_id, rating, comment)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $booking['station_id'], $booking_id, $rating, $comment]);

    // Stored column maintained app-side, in the same transaction.
    $stmt = $db->prepare("
        UPDATE stations
        SET average_rating = (SELECT ROUND(AVG(rating), 2) FROM ratings_reviews WHERE station_id = ? AND is_deleted = FALSE)
        WHERE id = ?
    ");
    $stmt->execute([$booking['station_id'], $booking['station_id']]);
    $avg = $db->prepare("SELECT average_rating FROM stations WHERE id = ?");
    $avg->execute([$booking['station_id']]);
    $new_average = (float) $avg->fetch()['average_rating'];

    $db->commit();
    echo json_encode(['status' => 'success', 'message' => 'Review submitted. Thank you!', 'data' => ['average_rating' => $new_average]]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    // 23000 = UNIQUE violation (parallel duplicate race) — same friendly answer.
    if ($e->getCode() === '23000') {
        echo json_encode(['status' => 'error', 'message' => 'You have already reviewed this session.']);
    } else {
        log_message('ERROR', 'Review create failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not submit review. Please try again.']);
    }
}