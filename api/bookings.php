<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';

Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$user_id = Auth::getCurrentUserId();
$user_type = Auth::getCurrentUserType();

try {
    $db = getDB();
    
    if ($method === 'GET') {
        if ($user_type === 'owner') {
            // Get bookings for all stations owned by this owner
            $stmt = $db->prepare("
                SELECT b.*, s.name as station_name, c.charger_number, c.charger_type, c.wattage_kw, 
                       u.name as user_name, u.phone as user_phone, cs.kwh_consumed, 
                       cs.start_time as session_start, cs.end_time as session_end
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                JOIN stations s ON c.station_id = s.id
                JOIN users u ON b.user_id = u.id
                LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
                WHERE s.owner_id = ?
                ORDER BY b.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$user_id]);
        } else {
            // Get user bookings (driver/default)
            $stmt = $db->prepare("
                SELECT b.*, s.name as station_name, s.latitude, s.longitude,
                       c.charger_type, c.wattage_kw, cs.kwh_consumed
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                JOIN stations s ON c.station_id = s.id
                LEFT JOIN charging_sessions cs ON b.id = cs.booking_id
                WHERE b.user_id = ?
                ORDER BY b.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$user_id]);
        }
        
        $bookings = $stmt->fetchAll();
        
        echo json_encode([
            'status' => 'success',
            'data' => $bookings
        ]);
        
    } elseif ($method === 'POST') {
        // Create booking
        $input = json_decode(file_get_contents('php://input'), true);
        
        $charger_id = intval($input['charger_id'] ?? 0);
        $battery_percent = intval($input['battery_percent'] ?? 50);
        
        // Get user details
        $stmt = $db->prepare("SELECT car_full_capacity_kwh FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        // Get charger details
        $stmt = $db->prepare("SELECT wattage_kw FROM chargers WHERE id = ?");
        $stmt->execute([$charger_id]);
        $charger = $stmt->fetch();
        
        // Calculate charge time
        $charge_time = ceil((100 - $battery_percent) / 100 * $user['car_full_capacity_kwh'] / $charger['wattage_kw'] * 60);
        
        // Calculate cost
        $kwh_needed = (100 - $battery_percent) / 100 * $user['car_full_capacity_kwh'];
        $cost = BOOKING_BASE_FEE + ($kwh_needed * ELECTRICITY_RATE_PER_KWH);
        
        $arrival_deadline = date('Y-m-d H:i:s', time() + (BOOKING_ARRIVAL_DEADLINE_MINUTES * 60));
        
        $stmt = $db->prepare("
            INSERT INTO bookings 
            (user_id, charger_id, car_current_battery_percent, car_full_capacity_kwh,
             calculated_charge_time_minutes, arrival_deadline, estimated_total_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id, $charger_id, $battery_percent, $user['car_full_capacity_kwh'],
            $charge_time, $arrival_deadline, $cost
        ]);
        
        $booking_id = $db->lastInsertId();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Booking created',
            'data' => ['booking_id' => $booking_id, 'cost' => $cost, 'charge_time' => $charge_time]
        ]);
        
    } elseif ($method === 'PUT') {
        $id = intval($_GET['id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($user_type === 'owner') {
            // Owner action (start or complete charging session)
            $action = sanitize($input['action'] ?? '');
            
            // Verify booking station belongs to this owner
            $stmt = $db->prepare("
                SELECT b.*, c.station_id
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                JOIN stations s ON c.station_id = s.id
                WHERE b.id = ? AND s.owner_id = ?
            ");
            $stmt->execute([$id, $user_id]);
            $booking = $stmt->fetch();
            
            if (!$booking) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Booking not found or access denied']);
                exit;
            }
            
            if ($action === 'start_session') {
                if ($booking['status'] !== 'booked') {
                    echo json_encode(['status' => 'error', 'message' => 'Session cannot be started from state: ' . $booking['status']]);
                    exit;
                }
                
                $db->beginTransaction();
                
                // Update booking status
                $stmt = $db->prepare("UPDATE bookings SET status = 'charging' WHERE id = ?");
                $stmt->execute([$id]);
                
                // Update charger status
                $stmt = $db->prepare("UPDATE chargers SET status = 'charging' WHERE id = ?");
                $stmt->execute([$booking['charger_id']]);
                
                // Create charging session
                $stmt = $db->prepare("
                    INSERT INTO charging_sessions (booking_id, start_time, battery_start_percent, per_kwh_rate, payment_status)
                    VALUES (?, NOW(), ?, ?, 'pending')
                ");
                $stmt->execute([$id, $booking['car_current_battery_percent'], ELECTRICITY_RATE_PER_KWH]);
                
                $db->commit();
                echo json_encode(['status' => 'success', 'message' => 'Charging session started successfully']);
                exit;
                
            } elseif ($action === 'complete_session') {
                if ($booking['status'] !== 'charging') {
                    echo json_encode(['status' => 'error', 'message' => 'Charging session is not active']);
                    exit;
                }
                
                // Get session details
                $stmt = $db->prepare("SELECT start_time, battery_start_percent FROM charging_sessions WHERE booking_id = ?");
                $stmt->execute([$id]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    echo json_encode(['status' => 'error', 'message' => 'Charging session not found']);
                    exit;
                }
                
                $db->beginTransaction();
                
                $start_time = strtotime($session['start_time']);
                $end_time = time();
                $duration_minutes = max(1, ceil(($end_time - $start_time) / 60));
                
                $capacity = floatval($booking['car_full_capacity_kwh']);
                $start_pct = intval($session['battery_start_percent']);
                
                // kWh consumed
                $kwh = (100 - $start_pct) / 100 * $capacity;
                $electricity_cost = $kwh * ELECTRICITY_RATE_PER_KWH;
                $total_amount = $booking['base_fee'] + $electricity_cost;
                
                // Update session
                $stmt = $db->prepare("
                    UPDATE charging_sessions SET 
                        end_time = NOW(),
                        battery_end_percent = 100,
                        kwh_consumed = ?,
                        actual_charge_time_minutes = ?,
                        electricity_cost = ?,
                        total_payment = ?,
                        payment_status = 'completed'
                    WHERE booking_id = ?
                ");
                $stmt->execute([$kwh, $duration_minutes, $electricity_cost, $total_amount, $id]);
                
                // Update booking
                $stmt = $db->prepare("
                    UPDATE bookings SET 
                        status = 'completed', 
                        payment_amount = ?, 
                        payment_status = 'completed' 
                    WHERE id = ?
                ");
                $stmt->execute([$total_amount, $id]);
                
                // Update charger
                $stmt = $db->prepare("UPDATE chargers SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['charger_id']]);
                
                // Update station stats
                $stmt = $db->prepare("
                    UPDATE stations SET 
                        total_bookings = total_bookings + 1,
                        total_revenue = total_revenue + ?,
                        total_kwh_consumed = total_kwh_consumed + ?
                    WHERE id = ?
                ");
                $stmt->execute([$total_amount, $kwh, $booking['station_id']]);
                
                $db->commit();
                echo json_encode(['status' => 'success', 'message' => 'Charging session completed and paid']);
                exit;
            }
            
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
            
        } else {
            // Driver action: Update booking status (e.g. cancel)
            $status = sanitize($input['status'] ?? '');
            
            $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$status, $id, $user_id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Booking updated']);
            exit;
        }
        
    } elseif ($method === 'DELETE') {
        // Cancel booking
        $id = intval($_GET['id'] ?? 0);
        
        $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        
        echo json_encode(['status' => 'success', 'message' => 'Booking cancelled']);
    }
    
} catch (Exception $e) {
    log_message('ERROR', "Booking API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>