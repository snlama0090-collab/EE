<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Location.php';
require_once '../app/helpers/Csrf.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

try {
    if ($method === 'GET') {
        // 1. Get specific station details
        if (isset($_GET['id'])) {
            $station_id = intval($_GET['id']);
            
            $stmt = $db->prepare("
                SELECT s.*, o.company_name as owner_company 
                FROM stations s 
                JOIN owners o ON s.owner_id = o.id 
                WHERE s.id = ?
            ");
            $stmt->execute([$station_id]);
            $station = $stmt->fetch();
            
            if (!$station) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Station not found']);
                exit;
            }
            
            // Get chargers with active booking counts
            $stmt = $db->prepare("
                SELECT c.*,
                       (SELECT COUNT(*) FROM bookings WHERE charger_id = c.id AND status IN ('booked', 'charging')) as active_booking_count
                FROM chargers c
                WHERE c.station_id = ?
                ORDER BY c.charger_number ASC
            ");
            $stmt->execute([$station_id]);
            $chargers = $stmt->fetchAll();

            // Compute bookable status per charger
            foreach ($chargers as &$c) {
                if ($c['status'] === 'maintenance' || $c['status'] === 'offline') {
                    $c['bookable'] = false;
                    $c['display_status'] = $c['status'] === 'maintenance' ? 'Maintenance' : 'Offline';
                } elseif ($c['active_booking_count'] == 0) {
                    $c['bookable'] = true;
                    $c['display_status'] = 'Available';
                } elseif ($c['active_booking_count'] == 1) {
                    // Check if the single active booking is 'booked' (reserved) or 'charging' (in use)
                    $stmt2 = $db->prepare("SELECT status FROM bookings WHERE charger_id = ? AND status IN ('booked', 'charging') ORDER BY created_at ASC LIMIT 1");
                    $stmt2->execute([$c['id']]);
                    $first = $stmt2->fetch();
                    if ($first && $first['status'] === 'booked') {
                        $c['bookable'] = false;
                        $c['display_status'] = 'Reserved';
                    } else {
                        $c['bookable'] = true;
                        $c['display_status'] = 'Charging — Available for Next Turn';
                    }
                } else {
                    $c['bookable'] = false;
                    $c['display_status'] = 'Charging — Fully Booked';
                }
            }
            unset($c);
            $station['chargers'] = $chargers;
            
            // Get reviews and average rating
            $stmt = $db->prepare("
                SELECT rr.*, u.name as user_name, u.profile_pic 
                FROM ratings_reviews rr
                JOIN users u ON rr.user_id = u.id
                WHERE rr.station_id = ? AND rr.is_deleted = FALSE
                ORDER BY rr.created_at DESC
            ");
            $stmt->execute([$station_id]);
            $reviews = $stmt->fetchAll();
            $station['reviews'] = $reviews;

            // Favorite status for the logged-in driver (if any)
            $station['is_favorite'] = false;
            $favUserId = Auth::getCurrentUserId();
            if ($favUserId) {
                $favStmt = $db->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND station_id = ? LIMIT 1");
                $favStmt->execute([$favUserId, $station_id]);
                $station['is_favorite'] = (bool) $favStmt->fetch();
            }

            echo json_encode([
                'status' => 'success',
                'data' => $station
            ]);
            exit;
        }
        
        // 2. Find nearby stations (Public / Driver / Owner)
        if (isset($_GET['latitude']) && isset($_GET['longitude'])) {
            $lat = floatval($_GET['latitude']);
            $lon = floatval($_GET['longitude']);
            $radius = floatval($_GET['radius'] ?? DEFAULT_SEARCH_RADIUS_KM);
            
            // Pre-filter with SQL bounding box — 1° lat ≈ 111 km
            $latOffset = $radius / 111.0;
            $lonOffset = $radius / (111.0 * cos(deg2rad($lat)));
            $minLat = $lat - $latOffset;
            $maxLat = $lat + $latOffset;
            $minLng = $lon - $lonOffset;
            $maxLng = $lon + $lonOffset;
            
            $stmt = $db->prepare("
                SELECT s.*, 
                       COUNT(c.id) as charger_count,
                       SUM(CASE WHEN c.status = 'available' AND (
                           SELECT COUNT(*) FROM bookings WHERE charger_id = c.id AND status IN ('booked', 'charging')
                       ) = 0 THEN 1
                       WHEN c.status = 'charging' AND (
                           SELECT COUNT(*) FROM bookings WHERE charger_id = c.id AND status IN ('booked', 'charging')
                       ) = 1 THEN 1
                       ELSE 0 END) as available_chargers,
                       GROUP_CONCAT(DISTINCT c.charger_type ORDER BY c.charger_type) as charger_types,
                       GROUP_CONCAT(DISTINCT CONCAT(c.charger_type, ' (', c.wattage_kw, 'kW)') ORDER BY c.wattage_kw DESC SEPARATOR ', ') as charger_details
                FROM stations s
                LEFT JOIN chargers c ON s.id = c.station_id
                WHERE s.approval_status = 'approved' AND s.is_active = TRUE AND s.deactivated_at IS NULL
                  AND s.latitude BETWEEN :min_lat AND :max_lat
                  AND s.longitude BETWEEN :min_lng AND :max_lng
                GROUP BY s.id
            ");
            $stmt->execute(['min_lat' => $minLat, 'max_lat' => $maxLat, 'min_lng' => $minLng, 'max_lng' => $maxLng]);
            $stations = $stmt->fetchAll();
            
            // Post-filter with precise Haversine distance + sort
            $nearby_stations = Location::getNearbyLocations($stations, $lat, $lon, $radius);
            
            echo json_encode([
                'status' => 'success',
                'data' => $nearby_stations
            ]);
            exit;
        }
        
        // 3. Authenticated actions (Owner or Admin lists)
        Auth::requireLogin();
        $user_id = Auth::getCurrentUserId();
        $user_type = Auth::getCurrentUserType();
        
        if ($user_type === 'owner') {
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
            
            echo json_encode([
                'status' => 'success',
                'data' => $stations
            ]);
            exit;
            
        } elseif ($user_type === 'admin') {
            // Get all stations for admin review
            $stmt = $db->prepare("
                SELECT s.*, o.company_name as owner_company 
                FROM stations s
                JOIN owners o ON s.owner_id = o.id
                ORDER BY s.created_at DESC
            ");
            $stmt->execute();
            $stations = $stmt->fetchAll();
            
            echo json_encode([
                'status' => 'success',
                'data' => $stations
            ]);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
    } elseif ($method === 'POST') {
        Auth::requireLogin();
        $user_id = Auth::getCurrentUserId();
        $user_type = Auth::getCurrentUserType();
        
        Csrf::validate();

        // 1. Admin Actions (Approve/Reject)
        if ($user_type === 'admin') {
            $action = sanitize($_GET['action'] ?? '');
            $station_id = intval($_GET['id'] ?? 0);
            
            if (!$station_id) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid station ID']);
                exit;
            }
            
            if ($action === 'approve') {
                // Fetch name + owner BEFORE the update so we can notify them
                $stmt = $db->prepare("SELECT name, owner_id FROM stations WHERE id = ?");
                $stmt->execute([$station_id]);
                $st = $stmt->fetch();

                $stmt = $db->prepare("UPDATE stations SET approval_status = 'approved', is_active = TRUE WHERE id = ?");
                $stmt->execute([$station_id]);

                if ($st) {
                    // Single row doubles as audit trail AND the owner's bell notification
                    $db->prepare("INSERT INTO activity_logs (admin_id, owner_id, action, resource_type, resource_id, details) VALUES (?, ?, 'station_approved', 'station', ?, ?)")
                       ->execute([$user_id, $st['owner_id'], $station_id, 'Your station "' . $st['name'] . '" has been approved and is now live.']);
                }

                echo json_encode(['status' => 'success', 'message' => 'Station approved successfully']);
                exit;

            } elseif ($action === 'reject') {
                $input = json_decode(file_get_contents('php://input'), true);
                $reason = sanitize($input['reason'] ?? 'No reason provided');

                $stmt = $db->prepare("SELECT name, owner_id FROM stations WHERE id = ?");
                $stmt->execute([$station_id]);
                $st = $stmt->fetch();

                $stmt = $db->prepare("UPDATE stations SET approval_status = 'rejected', rejection_reason = ? WHERE id = ?");
                $stmt->execute([$reason, $station_id]);

                if ($st) {
                    $details = 'Your station "' . $st['name'] . '" was rejected. Reason: ' . $reason;
                    $db->prepare("INSERT INTO activity_logs (admin_id, owner_id, action, resource_type, resource_id, details) VALUES (?, ?, 'station_rejected', 'station', ?, ?)")
                       ->execute([$user_id, $st['owner_id'], $station_id, $details]);
                }

                echo json_encode(['status' => 'success', 'message' => 'Station rejected']);
                exit;
            }

            // Admin action not recognized — fall through to shared/owner handlers below
            // (deactivate/reactivate are handled in the shared block for both roles)
        }

        // 2. Shared Actions (Owner AND Admin): deactivate/reactivate
        $action = sanitize($_GET['action'] ?? '');
        if ($action === 'deactivate') {
            // Deactivate a station. Owners can deactivate their own; admins can deactivate any station.
            // Admins MUST provide a reason; owners may optionally provide one.
            $input = json_decode(file_get_contents('php://input'), true);
            $target_id = intval($input['id'] ?? 0);
            $reason = isset($input['reason']) ? sanitize($input['reason']) : null;

            if ($user_type === 'admin') {
                // Admin: can deactivate any station, reason required
                if (empty($reason)) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'A reason is required when deactivating a station.']);
                    exit;
                }
                $stmt = $db->prepare("UPDATE stations SET deactivated_at = NOW(), deactivated_by = 'admin', deactivation_reason = ? WHERE id = ?");
                $stmt->execute([$reason, $target_id]);
            } else {
                // Owner: can only deactivate their own station
                $stmt = $db->prepare("UPDATE stations SET deactivated_at = NOW(), deactivated_by = 'owner', deactivation_reason = ? WHERE id = ? AND owner_id = ?");
                $stmt->execute([$reason, $target_id, $user_id]);
            }

            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Station not found or access denied.']);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => 'Station deactivated']);
            exit;
        }

        if ($action === 'reactivate') {
            // Reactivate a station. Owners can reactivate their own; admins can reactivate any.
            $input = json_decode(file_get_contents('php://input'), true);
            $target_id = intval($input['id'] ?? 0);

            if ($user_type === 'admin') {
                $stmt = $db->prepare("UPDATE stations SET deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL WHERE id = ?");
                $stmt->execute([$target_id]);
            } else {
                $stmt = $db->prepare("UPDATE stations SET deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL WHERE id = ? AND owner_id = ?");
                $stmt->execute([$target_id, $user_id]);
            }

            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Station not found or access denied.']);
                exit;
            }

            echo json_encode(['status' => 'success', 'message' => 'Station reactivated']);
            exit;
        }

        // 3. Owner Actions (owner-specific only)
        if ($user_type === 'owner') {
            $action = sanitize($_GET['action'] ?? '');
            if ($action === 'update_charger_status') {
                $input = json_decode(file_get_contents('php://input'), true);
                $charger_id = intval($input['charger_id'] ?? 0);
                $status = sanitize($input['status'] ?? '');

                // Whitelist: owners may only set available/maintenance/offline.
                // 'charging' is reserved for active booking sessions.
                $allowed_statuses = ['available', 'maintenance', 'offline'];
                if (!in_array($status, $allowed_statuses, true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid charger status']);
                    exit;
                }

                // Verify charger belongs to a station owned by this owner
                $stmt = $db->prepare("
                    SELECT c.id FROM chargers c 
                    JOIN stations s ON c.station_id = s.id 
                    WHERE c.id = ? AND s.owner_id = ?
                ");
                $stmt->execute([$charger_id, $user_id]);
                if (!$stmt->fetch()) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE chargers SET status = ? WHERE id = ?");
                $stmt->execute([$status, $charger_id]);
                
                echo json_encode(['status' => 'success', 'message' => 'Charger status updated successfully']);
                exit;
            }

            if ($action === 'deactivate') {
                // Deactivate a station. Owners can deactivate their own; admins can deactivate any station.
                // Admins MUST provide a reason; owners may optionally provide one.
                $input = json_decode(file_get_contents('php://input'), true);
                $target_id = intval($input['id'] ?? 0);
                $reason = isset($input['reason']) ? sanitize($input['reason']) : null;

                if ($user_type === 'admin') {
                    // Admin: can deactivate any station, reason required
                    if (empty($reason)) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'A reason is required when deactivating a station.']);
                        exit;
                    }
                    $stmt = $db->prepare("UPDATE stations SET deactivated_at = NOW(), deactivated_by = 'admin', deactivation_reason = ? WHERE id = ?");
                    $stmt->execute([$reason, $target_id]);
                } else {
                    // Owner: can only deactivate their own station
                    $stmt = $db->prepare("UPDATE stations SET deactivated_at = NOW(), deactivated_by = 'owner', deactivation_reason = ? WHERE id = ? AND owner_id = ?");
                    $stmt->execute([$reason, $target_id, $user_id]);
                }

                if ($stmt->rowCount() === 0) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Station not found or access denied.']);
                    exit;
                }

                echo json_encode(['status' => 'success', 'message' => 'Station deactivated']);
                exit;
            }

            if ($action === 'reactivate') {
                // Reactivate a station. Owners can reactivate their own; admins can reactivate any.
                $input = json_decode(file_get_contents('php://input'), true);
                $target_id = intval($input['id'] ?? 0);

                if ($user_type === 'admin') {
                    $stmt = $db->prepare("UPDATE stations SET deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL WHERE id = ?");
                    $stmt->execute([$target_id]);
                } else {
                    $stmt = $db->prepare("UPDATE stations SET deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL WHERE id = ? AND owner_id = ?");
                    $stmt->execute([$target_id, $user_id]);
                }

                if ($stmt->rowCount() === 0) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Station not found or access denied.']);
                    exit;
                }

                echo json_encode(['status' => 'success', 'message' => 'Station reactivated']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
                exit;
            }
            
            $name = sanitize($input['name'] ?? '');
            $description = sanitize($input['description'] ?? '');
            $latitude = floatval($input['latitude'] ?? 0);
            $longitude = floatval($input['longitude'] ?? 0);
            $address = sanitize($input['address'] ?? '');
            $city = sanitize($input['city'] ?? '');
            $chargers = $input['chargers'] ?? [];
            
            if (empty($name) || !$latitude || !$longitude || empty($address) || empty($city)) {
                echo json_encode(['status' => 'error', 'message' => 'All location details are required']);
                exit;
            }
            
            $db->beginTransaction();
            
            // Insert station
            $stmt = $db->prepare("
                INSERT INTO stations (owner_id, name, description, latitude, longitude, address, city, num_chargers, approval_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$user_id, $name, $description, $latitude, $longitude, $address, $city, count($chargers)]);
            $station_id = $db->lastInsertId();
            
            // Insert chargers
            $charger_number = 1;
            $stmt_charger = $db->prepare("
                INSERT INTO chargers (station_id, charger_number, charger_type, wattage_kw, status)
                VALUES (?, ?, ?, ?, 'available')
            ");
            
            foreach ($chargers as $charger) {
                $type = sanitize($charger['type'] ?? 'AC Standard');
                $wattage = floatval($charger['wattage'] ?? 7.4);
                $stmt_charger->execute([$station_id, $charger_number++, $type, $wattage]);
            }
            
            $db->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Station submitted for approval',
                'data' => ['station_id' => $station_id]
            ]);
            exit;
        }
        
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
        
    } elseif ($method === 'PUT') {
        Auth::requireLogin();
        Csrf::validate();
        $user_id = Auth::getCurrentUserId();
        $user_type = Auth::getCurrentUserType();
        
        if ($user_type !== 'owner') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only station owners can update stations']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $station_id = intval($input['id'] ?? 0);
        
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM stations WHERE id = ? AND owner_id = ?");
        $stmt->execute([$station_id, $user_id]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied. You do not own this station.']);
            exit;
        }
        
        $name = sanitize($input['name'] ?? '');
        $description = sanitize($input['description'] ?? '');
        $latitude = floatval($input['latitude'] ?? 0);
        $longitude = floatval($input['longitude'] ?? 0);
        $address = sanitize($input['address'] ?? '');
        $city = sanitize($input['city'] ?? '');
        
        $stmt = $db->prepare("
            UPDATE stations 
            SET name = ?, description = ?, latitude = ?, longitude = ?, address = ?, city = ?, approval_status = 'pending'
            WHERE id = ? AND owner_id = ?
        ");
        $stmt->execute([$name, $description, $latitude, $longitude, $address, $city, $station_id, $user_id]);
        
        echo json_encode(['status' => 'success', 'message' => 'Station details updated. Awaiting re-approval.']);
        exit;
        
    } elseif ($method === 'DELETE') {
        Auth::requireLogin();
        Csrf::validate();
        $user_id = Auth::getCurrentUserId();
        $user_type = Auth::getCurrentUserType();
        
        $station_id = intval($_GET['id'] ?? 0);

        // Guard: stations with booking/payment history cannot be hard-deleted.
        // History must be preserved — deactivate instead.
        // Both bookings and payment_transactions trace to stations via chargers (charger_id → chargers.station_id).
        $stmt = $db->prepare("SELECT COUNT(*) as history_count FROM bookings b JOIN chargers c ON b.charger_id = c.id WHERE c.station_id = ?");
        $stmt->execute([$station_id]);
        $history = $stmt->fetch();
        if ($history['history_count'] > 0) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'This station has booking or payment history and cannot be deleted. Deactivate it instead.']);
            exit;
        }

        if ($user_type === 'admin') {
            $stmt = $db->prepare("DELETE FROM stations WHERE id = ?");
            $stmt->execute([$station_id]);
            echo json_encode(['status' => 'success', 'message' => 'Station deleted by admin']);
            exit;
        }
        
        if ($user_type === 'owner') {
            // Verify ownership
            $stmt = $db->prepare("SELECT id FROM stations WHERE id = ? AND owner_id = ?");
            $stmt->execute([$station_id, $user_id]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
            
            $stmt = $db->prepare("DELETE FROM stations WHERE id = ? AND owner_id = ?");
            $stmt->execute([$station_id, $user_id]);
            echo json_encode(['status' => 'success', 'message' => 'Station deleted successfully']);
            exit;
        }
        
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    
} catch (Exception $e) {
    log_message('ERROR', "Stations API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please try again later.']);
}
?>
