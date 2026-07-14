<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/Location.php';

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
                       SUM(CASE WHEN c.status = 'available' THEN 1 ELSE 0 END) as available_chargers,
                       GROUP_CONCAT(DISTINCT c.charger_type ORDER BY c.charger_type) as charger_types,
                       GROUP_CONCAT(DISTINCT CONCAT(c.charger_type, ' (', c.wattage_kw, 'kW)') ORDER BY c.wattage_kw DESC SEPARATOR ', ') as charger_details
                FROM stations s
                LEFT JOIN chargers c ON s.id = c.station_id
                WHERE s.approval_status = 'approved' AND s.is_active = TRUE
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
        
        // 1. Admin Actions (Approve/Reject)
        if ($user_type === 'admin') {
            $action = sanitize($_GET['action'] ?? '');
            $station_id = intval($_GET['id'] ?? 0);
            
            if (!$station_id) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid station ID']);
                exit;
            }
            
            if ($action === 'approve') {
                $stmt = $db->prepare("UPDATE stations SET approval_status = 'approved', is_active = TRUE WHERE id = ?");
                $stmt->execute([$station_id]);
                
                // Log action
                $stmt = $db->prepare("INSERT INTO activity_logs (admin_id, action, resource_type, resource_id) VALUES (?, 'approve', 'station', ?)");
                $stmt->execute([$user_id, $station_id]);
                
                echo json_encode(['status' => 'success', 'message' => 'Station approved successfully']);
                exit;
                
            } elseif ($action === 'reject') {
                $input = json_decode(file_get_contents('php://input'), true);
                $reason = sanitize($input['reason'] ?? 'No reason provided');
                
                $stmt = $db->prepare("UPDATE stations SET approval_status = 'rejected', rejection_reason = ? WHERE id = ?");
                $stmt->execute([$reason, $station_id]);
                
                // Log action
                $stmt = $db->prepare("INSERT INTO activity_logs (admin_id, action, resource_type, resource_id, details) VALUES (?, 'reject', 'station', ?, ?)");
                $stmt->execute([$user_id, $station_id, json_encode(['reason' => $reason])]);
                
                echo json_encode(['status' => 'success', 'message' => 'Station rejected']);
                exit;
            }
            
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
        }
        
        // 2. Owner Actions
        if ($user_type === 'owner') {
            $action = sanitize($_GET['action'] ?? '');
            if ($action === 'update_charger_status') {
                $input = json_decode(file_get_contents('php://input'), true);
                $charger_id = intval($input['charger_id'] ?? 0);
                $status = sanitize($input['status'] ?? '');
                
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
        $user_id = Auth::getCurrentUserId();
        $user_type = Auth::getCurrentUserType();
        
        $station_id = intval($_GET['id'] ?? 0);
        
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
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
