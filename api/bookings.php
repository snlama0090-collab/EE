<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/SessionTicker.php';

Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$user_id = Auth::getCurrentUserId();
$user_type = Auth::getCurrentUserType();

try {
    $db = getDB();
    
    // Lazy tick: auto-complete any overdue charging sessions on every request
    tickChargingSessions($db);

    if ($method === 'GET') {
        if ($user_type === 'owner') {
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
        $input = json_decode(file_get_contents('php://input'), true);
        $action = sanitize($input['action'] ?? '');
        
        // Action: initiate_payment — driver submits battery %, backend calculates price
        if ($action === 'initiate_payment') {
            $charger_id = intval($input['charger_id'] ?? 0);
            $battery_percent = intval($input['current_percentage'] ?? 0);
            
            if ($battery_percent < 1 || $battery_percent > 100) {
                echo json_encode(['status' => 'error', 'message' => 'Current battery percentage must be between 1 and 100.']);
                exit;
            }
            
            // Get charger details
            $stmt = $db->prepare("SELECT c.*, s.owner_id FROM chargers c JOIN stations s ON c.station_id = s.id WHERE c.id = ?");
            $stmt->execute([$charger_id]);
            $charger = $stmt->fetch();
            
            if (!$charger) {
                echo json_encode(['status' => 'error', 'message' => 'Charger not found']);
                exit;
            }
            
            // Get user details
            $stmt = $db->prepare("SELECT car_full_capacity_kwh FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            // Bookable check + insert in a single transaction
            $db->beginTransaction();
            
            // Re-check bookable rule immediately before insert
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE charger_id = ? AND status IN ('booked', 'pending_payment', 'charging')");
            $stmt->execute([$charger_id]);
            $active_count = intval($stmt->fetch()['cnt']);
            
            if ($active_count >= 2) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'This charger\'s queue is full. Please select another charger or station.']);
                exit;
            }
            
            if ($active_count == 1) {
                $stmt = $db->prepare("SELECT status FROM bookings WHERE charger_id = ? AND status IN ('booked', 'pending_payment', 'charging') ORDER BY created_at ASC LIMIT 1");
                $stmt->execute([$charger_id]);
                $first = $stmt->fetch();
                if ($first && in_array($first['status'], ['booked', 'pending_payment'])) {
                    $db->rollBack();
                    echo json_encode(['status' => 'error', 'message' => 'This charger is already reserved by another driver.']);
                    exit;
                }
            }
            
            if ($charger['status'] === 'maintenance' || $charger['status'] === 'offline') {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'This charger is currently unavailable.']);
                exit;
            }
            
            // Calculate charge time and cost based on battery %
            $capacity = floatval($user['car_full_capacity_kwh']);
            $wattage = floatval($charger['wattage_kw']);
            $charge_time_minutes = ceil((100 - $battery_percent) / 100 * $capacity / $wattage * 60);
            $kwh_needed = (100 - $battery_percent) / 100 * $capacity;
            $total_cost = BOOKING_BASE_FEE + ($kwh_needed * ELECTRICITY_RATE_PER_KWH);
            
            $arrival_deadline = date('Y-m-d H:i:s', time() + (BOOKING_ARRIVAL_DEADLINE_MINUTES * 60));
            
            $stmt = $db->prepare("
                INSERT INTO bookings 
                (user_id, charger_id, car_current_battery_percent, car_full_capacity_kwh,
                 calculated_charge_time_minutes, arrival_deadline, estimated_total_cost, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_payment')
            ");
            
            $stmt->execute([
                $user_id, $charger_id, $battery_percent, $capacity,
                $charge_time_minutes, $arrival_deadline, $total_cost
            ]);
            
            $booking_id = $db->lastInsertId();
            $db->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment initiation request created',
                'data' => [
                    'booking_id' => $booking_id,
                    'estimated_cost' => $total_cost,
                    'charge_time_minutes' => $charge_time_minutes,
                    'kwh_needed' => round($kwh_needed, 2),
                    'currency' => 'NPR'
                ]
            ]);
            exit;
        }
        
        // Action: confirm_payment — simulated payment confirmation
        if ($action === 'confirm_payment') {
            $booking_id = intval($input['booking_id'] ?? 0);
            
            if ($user_type !== 'driver') {
                echo json_encode(['status' => 'error', 'message' => 'Only drivers can confirm payment.']);
                exit;
            }
            
            // Verify the booking belongs to this driver and is in pending_payment state
            $stmt = $db->prepare("SELECT b.*, c.wattage_kw FROM bookings b JOIN chargers c ON b.charger_id = c.id WHERE b.id = ? AND b.user_id = ?");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();
            
            if (!$booking) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
                exit;
            }
            
            if ($booking['status'] !== 'pending_payment') {
                echo json_encode(['status' => 'error', 'message' => 'Booking is not in a payable state: ' . $booking['status']]);
                exit;
            }
            
            $db->beginTransaction();
            
            // Set buffer and session timers
            $calculated_minutes = intval($booking['calculated_charge_time_minutes']);
            if ($calculated_minutes <= 0) $calculated_minutes = 30; // fallback
            
            $stmt = $db->prepare("
                UPDATE bookings SET 
                    status = 'charging',
                    payment_status = 'completed',
                    buffer_ends_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                    session_ends_at = DATE_ADD(DATE_ADD(NOW(), INTERVAL 5 MINUTE), INTERVAL ? MINUTE)
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$calculated_minutes, $booking_id, $user_id]);
            
            // Update charger status
            $stmt = $db->prepare("UPDATE chargers SET status = 'charging' WHERE id = ?");
            $stmt->execute([$booking['charger_id']]);
            
            // Create charging session
            $stmt = $db->prepare("
                INSERT INTO charging_sessions (booking_id, start_time, battery_start_percent, per_kwh_rate, payment_status)
                VALUES (?, NOW(), ?, ?, 'completed')
            ");
            $stmt->execute([$booking_id, $booking['car_current_battery_percent'], ELECTRICITY_RATE_PER_KWH]);
            
            // Log payment transaction
            $transaction_id = 'TXN' . time() . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
            $stmt = $db->prepare("
                INSERT INTO payment_transactions (booking_id, charging_session_id, transaction_id, payment_method, amount, currency, status)
                VALUES (?, LAST_INSERT_ID(), ?, 'wallet', ?, 'NPR', 'completed')
            ");
            $stmt->execute([$booking_id, $transaction_id, $booking['estimated_total_cost']]);
            
            $db->commit();
            
            // Re-fetch to return the updated timestamps
            $stmt = $db->prepare("SELECT id, status, buffer_ends_at, session_ends_at, estimated_total_cost FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $updated = $stmt->fetch();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment confirmed. Charging session started.',
                'data' => $updated
            ]);
            exit;
        }
        
        // Fallback: legacy booking creation (flat fee, kept for backward compat)
        $charger_id = intval($input['charger_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT c.*, s.owner_id FROM chargers c JOIN stations s ON c.station_id = s.id WHERE c.id = ?");
        $stmt->execute([$charger_id]);
        $charger = $stmt->fetch();
        
        if (!$charger) {
            echo json_encode(['status' => 'error', 'message' => 'Charger not found']);
            exit;
        }
        
        $stmt = $db->prepare("SELECT car_full_capacity_kwh FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE charger_id = ? AND status IN ('booked', 'pending_payment', 'charging')");
        $stmt->execute([$charger_id]);
        $active_count = intval($stmt->fetch()['cnt']);
        
        if ($active_count >= 2) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'This charger\'s queue is full.']);
            exit;
        }
        
        if ($active_count == 1) {
            $stmt = $db->prepare("SELECT status FROM bookings WHERE charger_id = ? AND status IN ('booked', 'pending_payment', 'charging') ORDER BY created_at ASC LIMIT 1");
            $stmt->execute([$charger_id]);
            $first = $stmt->fetch();
            if ($first && in_array($first['status'], ['booked', 'pending_payment'])) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'This charger is already reserved.']);
                exit;
            }
        }
        
        if ($charger['status'] === 'maintenance' || $charger['status'] === 'offline') {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'This charger is currently unavailable.']);
            exit;
        }
        
        $cost = BOOKING_BASE_FEE;
        $arrival_deadline = date('Y-m-d H:i:s', time() + (BOOKING_ARRIVAL_DEADLINE_MINUTES * 60));
        
        $stmt = $db->prepare("
            INSERT INTO bookings 
            (user_id, charger_id, car_current_battery_percent, car_full_capacity_kwh,
             calculated_charge_time_minutes, arrival_deadline, estimated_total_cost)
            VALUES (?, ?, NULL, ?, NULL, ?, ?)
        ");
        
        $stmt->execute([
            $user_id, $charger_id, $user['car_full_capacity_kwh'],
            $arrival_deadline, $cost
        ]);
        
        $booking_id = $db->lastInsertId();
        $db->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Booking created',
            'data' => ['booking_id' => $booking_id, 'cost' => $cost]
        ]);
        
    } elseif ($method === 'PUT') {
        $id = intval($_GET['id'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($user_type === 'owner') {
            $action = sanitize($input['action'] ?? '');
            
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
                if ($booking['status'] !== 'booked' && $booking['status'] !== 'pending_payment') {
                    echo json_encode(['status' => 'error', 'message' => 'Session cannot be started from state: ' . $booking['status']]);
                    exit;
                }
                
                // ponytail: owner plugs in and starts — timer already set if payment confirmed
                if ($booking['status'] === 'booked') {
                    // No payment — set tickers now (legacy path)
                    $battery_percent = intval($input['battery_percent'] ?? 0);
                    if ($battery_percent < 1 || $battery_percent > 100) {
                        echo json_encode(['status' => 'error', 'message' => 'Please provide the current battery percentage (1–100).']);
                        exit;
                    }
                    
                    $db->beginTransaction();
                    
                    $stmt2 = $db->prepare("SELECT wattage_kw FROM chargers WHERE id = ?");
                    $stmt2->execute([$booking['charger_id']]);
                    $charger_data = $stmt2->fetch();
                    $capacity = floatval($booking['car_full_capacity_kwh']);
                    $wattage = floatval($charger_data['wattage_kw']);
                    
                    $charge_time = ceil((100 - $battery_percent) / 100 * $capacity / $wattage * 60);
                    $kwh_needed = (100 - $battery_percent) / 100 * $capacity;
                    $estimated_cost = BOOKING_BASE_FEE + ($kwh_needed * ELECTRICITY_RATE_PER_KWH);
                    
                    $stmt = $db->prepare("UPDATE bookings SET car_current_battery_percent = ?, calculated_charge_time_minutes = ?, estimated_total_cost = ?, buffer_ends_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE), session_ends_at = DATE_ADD(DATE_ADD(NOW(), INTERVAL 5 MINUTE), INTERVAL ? MINUTE), status = 'charging' WHERE id = ?");
                    $stmt->execute([$battery_percent, $charge_time, $estimated_cost, $charge_time, $id]);
                    
                    $stmt = $db->prepare("UPDATE chargers SET status = 'charging' WHERE id = ?");
                    $stmt->execute([$booking['charger_id']]);
                    
                    $stmt = $db->prepare("INSERT INTO charging_sessions (booking_id, start_time, battery_start_percent, per_kwh_rate, payment_status) VALUES (?, NOW(), ?, ?, 'pending')");
                    $stmt->execute([$id, $battery_percent, ELECTRICITY_RATE_PER_KWH]);
                    
                    $db->commit();
                    echo json_encode(['status' => 'success', 'message' => 'Charging session started successfully']);
                    exit;
                }
                
                // pending_payment path — owner just confirms plug-in; buffers already set
                $db->beginTransaction();
                $stmt = $db->prepare("UPDATE bookings SET status = 'charging' WHERE id = ?");
                $stmt->execute([$id]);
                $stmt = $db->prepare("UPDATE chargers SET status = 'charging' WHERE id = ?");
                $stmt->execute([$booking['charger_id']]);
                $db->commit();
                
                echo json_encode(['status' => 'success', 'message' => 'Charging session started successfully']);
                exit;
                
            } elseif ($action === 'complete_session') {
                if ($booking['status'] !== 'charging') {
                    echo json_encode(['status' => 'error', 'message' => 'Charging session is not active']);
                    exit;
                }
                
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
                
                $kwh = (100 - $start_pct) / 100 * $capacity;
                $electricity_cost = $kwh * ELECTRICITY_RATE_PER_KWH;
                $total_amount = $booking['base_fee'] + $electricity_cost;
                
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
                
                $stmt = $db->prepare("UPDATE bookings SET status = 'completed', payment_amount = ?, payment_status = 'completed' WHERE id = ?");
                $stmt->execute([$total_amount, $id]);
                
                $stmt = $db->prepare("UPDATE chargers SET status = 'available' WHERE id = ?");
                $stmt->execute([$booking['charger_id']]);
                
                $stmt = $db->prepare("UPDATE stations SET total_bookings = total_bookings + 1, total_revenue = total_revenue + ?, total_kwh_consumed = total_kwh_consumed + ? WHERE id = ?");
                $stmt->execute([$total_amount, $kwh, $booking['station_id']]);
                
                $db->commit();
                echo json_encode(['status' => 'success', 'message' => 'Charging session completed and paid']);
                exit;
            }
            
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
            
        } else {
            $status = sanitize($input['status'] ?? '');
            $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$status, $id, $user_id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Booking updated']);
            exit;
        }
        
    } elseif ($method === 'DELETE') {
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