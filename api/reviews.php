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

// Boot the session (the old requireUserType('driver') did this implicitly;
// the Phase-2 role-routing restructure reads the session directly, so the
// boot must be explicit — without it $_SESSION is empty and every role
// check / CSRF validation fails).
Auth::boot();

$user_type = Auth::getCurrentUserType();
$user_id   = Auth::getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Read-only GETs: deliberately NOT CSRF-gated (design decision above).
    $db = getDB();
    if ($user_type === 'driver') {
        // Station review list for the booking modal (Phase 1).
        $station_id = intval($_GET['station_id'] ?? 0);
        if ($station_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'station_id required']);
            exit;
        }
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
    if ($user_type === 'owner') {
        // Phase 2: reviews on this owner's stations (flagged first).
        $stmt = $db->prepare("
            SELECT rr.id, rr.rating, rr.comment, rr.created_at, rr.is_flagged, rr.flag_reason,
                   u.name AS user_name, s.name AS station_name
            FROM ratings_reviews rr
            JOIN stations s ON rr.station_id = s.id
            JOIN users u ON rr.user_id = u.id
            WHERE s.owner_id = ? AND rr.is_deleted = FALSE
            ORDER BY rr.is_flagged DESC, rr.created_at DESC
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['status' => 'success', 'data' => ['reviews' => $stmt->fetchAll()]]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
    exit;
}

// ── POST: create a review for a finished session ──
Csrf::validate();

$input = json_decode(file_get_contents('php://input'), true);
$action = sanitize($input['action'] ?? 'create');
$booking_id = intval($input['booking_id'] ?? 0);
$rating = intval($input['rating'] ?? 0);
$comment = trim($input['comment'] ?? '');

// ROUTER GATE (2026-08-31): create must be gated on its action — without this
// gate the create validation ran for EVERY POST and flag/moderate/warn were
// unreachable (flag requests died with "Rating must be between 1 and 5 stars").
if ($action === 'create') {
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
    if ($user_type !== 'driver') {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Only drivers can submit reviews.']);
        exit;
    }

    // Eligibility: this driver's booking, finished charging (completed or
    // stopped — both billed, neither cancelled), mapped to its station.
    // updated_at is the terminal-state timestamp for both: SessionTicker's
    // status='completed' UPDATE and stop_session's status='stopped' UPDATE
    // both fire ON UPDATE CURRENT_TIMESTAMP. (session_ends_at is NOT reliable
    // for 'stopped' — stop_session never updates it.)
    $stmt = $db->prepare("
        SELECT b.id, b.status, b.updated_at, c.station_id
        FROM bookings b
        JOIN chargers c ON b.charger_id = c.id
        WHERE b.id = ? AND b.user_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch();

    if (!$booking || !in_array($booking['status'], ['completed', 'stopped'])) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Booking not found, not finished, or not yours.']);
        exit;
    }

    // 24-hour review window — driver must review within 24h of the session ending.
    if (strtotime($booking['updated_at']) < time() - 86400) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'The 24-hour review window for this session has closed.']);
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
exit; // create is terminal — without this, execution falls through to the "Unknown action." fallthrough and the success body gets a second JSON document appended (19b null, 2026-08-31)
} // end $action === 'create'

// ── owner: flag a review on one of their own stations ──
if ($action === 'flag') {
    if ($user_type !== 'owner') {
        echo json_encode(['status' => 'error', 'message' => 'Only station owners can flag reviews.']);
        exit;
    }
    $user_id = Auth::getCurrentUserId();
    $review_id = intval($input['review_id'] ?? 0);
    $reason = trim($input['reason'] ?? '');
    if ($review_id <= 0 || $reason === '') {
        echo json_encode(['status' => 'error', 'message' => 'A reason is required to flag a review.']);
        exit;
    }
    if (mb_strlen($reason) > 255) {
        echo json_encode(['status' => 'error', 'message' => 'Reason must be 255 characters or fewer.']);
        exit;
    }
    $db = getDB();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("
            SELECT rr.id, rr.user_id, rr.is_flagged, rr.is_deleted, s.owner_id, s.name AS station_name
            FROM ratings_reviews rr
            JOIN stations s ON rr.station_id = s.id
            WHERE rr.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$review_id]);
        $rv = $stmt->fetch();
        if (!$rv || $rv['is_deleted']) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Review not found.']);
            exit;
        }
        if ((int) $rv['owner_id'] !== (int) $user_id) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'You can only flag reviews on your own stations.']);
            exit;
        }
        if ($rv['is_flagged']) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'This review is already flagged.']);
            exit;
        }
        $db->prepare("UPDATE ratings_reviews SET is_flagged = TRUE, flag_reason = ? WHERE id = ?")
           ->execute([$reason, $review_id]);
        // Notify the review author (their review was flagged for moderation).
        $db->prepare("INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details) VALUES (?, 'review_flagged', 'review', ?, ?)")
           ->execute([$rv['user_id'], $review_id, 'Your review of "' . $rv['station_name'] . '" was flagged and is awaiting moderation.']);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Review flagged for moderation.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        log_message('ERROR', 'Review flag failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not flag review. Please try again.']);
    }
    exit;
}

// ── admin: resolve a flagged review (remove it, or dismiss the flag) ──
if ($action === 'moderate') {
    if ($user_type !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Admin only.']);
        exit;
    }
    $db = getDB();
    $canStmt = $db->prepare("SELECT can_moderate_reviews FROM admins WHERE id = ?");
    $canStmt->execute([$user_id]);
    $canRow = $canStmt->fetch();
    if (!$canRow || !(int) $canRow['can_moderate_reviews']) {
        echo json_encode(['status' => 'error', 'message' => 'Your admin account cannot moderate reviews.']);
        exit;
    }
    $review_id = intval($input['review_id'] ?? 0);
    $decision = sanitize($input['decision'] ?? '');
    if ($review_id <= 0 || !in_array($decision, ['remove', 'dismiss'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'decision must be remove or dismiss.']);
        exit;
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("
            SELECT rr.id, rr.user_id, rr.rating, rr.station_id, rr.is_deleted, s.owner_id, s.name AS station_name
            FROM ratings_reviews rr
            JOIN stations s ON rr.station_id = s.id
            WHERE rr.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$review_id]);
        $rv = $stmt->fetch();
        if (!$rv || $rv['is_deleted']) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Review not found or already removed.']);
            exit;
        }
        if ($decision === 'remove') {
            $db->prepare("UPDATE ratings_reviews SET is_deleted = TRUE WHERE id = ?")->execute([$review_id]);
            $db->prepare("
                UPDATE stations
                SET average_rating = (SELECT ROUND(AVG(rating), 2) FROM ratings_reviews WHERE station_id = ? AND is_deleted = FALSE)
                WHERE id = ?
            ")->execute([$rv['station_id'], $rv['station_id']]);
            $db->prepare("INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details) VALUES (?, 'review_removed', 'review', ?, ?)")
               ->execute([$rv['user_id'], $review_id, 'Your review of "' . $rv['station_name'] . '" was removed by moderation.']);
            $db->prepare("INSERT INTO activity_logs (owner_id, action, resource_type, resource_id, details) VALUES (?, 'review_removed', 'review', ?, ?)")
               ->execute([$rv['owner_id'], $review_id, 'A flagged review on "' . $rv['station_name'] . '" was removed by moderation.']);
            $message = 'Review removed.';
        } else {
            $db->prepare("UPDATE ratings_reviews SET is_flagged = FALSE, flag_reason = '' WHERE id = ?")->execute([$review_id]);
            $db->prepare("INSERT INTO activity_logs (owner_id, action, resource_type, resource_id, details) VALUES (?, 'review_flag_dismissed', 'review', ?, ?)")
               ->execute([$rv['owner_id'], $review_id, 'The flag you raised on "' . $rv['station_name'] . '" was reviewed and dismissed. The review remains visible.']);
            $message = 'Flag dismissed. The review remains visible.';
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => $message]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        log_message('ERROR', 'Review moderation failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Moderation action failed. Please try again.']);
    }
    exit;
}

// ── admin: issue a formal warning to an owner ──
if ($action === 'warn') {
    if ($user_type !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Admin only.']);
        exit;
    }
    $db = getDB();
    $canStmt = $db->prepare("SELECT can_moderate_reviews FROM admins WHERE id = ?");
    $canStmt->execute([$user_id]);
    $canRow = $canStmt->fetch();
    if (!$canRow || !(int) $canRow['can_moderate_reviews']) {
        echo json_encode(['status' => 'error', 'message' => 'Your admin account cannot moderate reviews.']);
        exit;
    }
    $owner_id = intval($input['owner_id'] ?? 0);
    $reason = trim($input['reason'] ?? '');
    if ($owner_id <= 0 || $reason === '') {
        echo json_encode(['status' => 'error', 'message' => 'A reason is required.']);
        exit;
    }
    if (mb_strlen($reason) > 255) {
        echo json_encode(['status' => 'error', 'message' => 'Reason must be 255 characters or fewer.']);
        exit;
    }
    try {
        $db->beginTransaction();
        $own = $db->prepare("SELECT id FROM owners WHERE id = ? FOR UPDATE");
        $own->execute([$owner_id]);
        if (!$own->fetch()) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Owner not found.']);
            exit;
        }
        // owners.warning_count arrives with the Phase-2 ALTER — this action
        // activates once that column exists; not functional before it.
        $db->prepare("UPDATE owners SET warning_count = COALESCE(warning_count, 0) + 1 WHERE id = ?")->execute([$owner_id]);
        $db->prepare("INSERT INTO activity_logs (owner_id, action, resource_type, resource_id, details) VALUES (?, 'owner_warning', 'owner', ?, ?)")
           ->execute([$owner_id, $owner_id, 'Formal warning issued by moderation: ' . $reason]);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => 'Warning issued.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        log_message('ERROR', 'Owner warning failed: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not issue warning. Please try again.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
exit;