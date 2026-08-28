<?php
header('Content-Type: application/json');
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';
require_once '../app/helpers/SessionTicker.php';
require_once '../app/helpers/Csrf.php';

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
        Csrf::validate();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = sanitize($input['action'] ?? '');
        
        // Action: initiate_payment — flat reservation fee, no battery input
        if ($action === 'initiate_payment') {
            $charger_id = intval($input['charger_id'] ?? 0);
            
            // Get charger details
            $stmt = $db->prepare("SELECT c.*, s.owner_id FROM chargers c JOIN stations s ON c.station_id = s.id WHERE c.id = ?");
            $stmt->execute([$charger_id]);
            $charger = $stmt->fetch();
            
            if (!$charger) {
                echo json_encode(['status' => 'error', 'message' => 'Charger not found']);
                exit;
            }
            
            // Get user's car capacity for later (at session start)
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
            
            // Flat reservation fee — battery % and charging cost calculated at session start
            $capacity = floatval($user['car_full_capacity_kwh']);
            $total_cost = BOOKING_BASE_FEE;
            
            $arrival_deadline = date('Y-m-d H:i:s', time() + (BOOKING_ARRIVAL_DEADLINE_MINUTES * 60));
            
            $stmt = $db->prepare("
                INSERT INTO bookings 
                (user_id, charger_id, car_full_capacity_kwh,
                 arrival_deadline, estimated_total_cost, base_fee, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending_payment')
            ");
            
            $stmt->execute([
                $user_id, $charger_id, $capacity,
                $arrival_deadline, $total_cost, $total_cost
            ]);
            
            $booking_id = $db->lastInsertId();
            $db->commit();
            
            // Khalti mode: create the real gateway payment and hand back the hosted payment URL.
            // Simulated mode falls through to the original response below, unchanged.
            if (PAYMENT_DRIVER === 'khalti') {
                require_once __DIR__ . '/../app/helpers/KhaltiPayment.php';
                
                $gw = KhaltiPayment::initiate($booking_id, BOOKING_BASE_FEE, 'EV Charging Reservation Fee');
                if (!$gw['ok']) {
                    // Gateway initiation failed — booking stays pending_payment and the
                    // existing expiry flow (SessionTicker) cancels it and frees the charger.
                    echo json_encode([
                        'status' => 'error',
                        'message' => $gw['error'],
                        'data' => ['booking_id' => $booking_id]
                    ]);
                    exit;
                }
                
                // Pending transaction row: transaction_id + gateway_payment_id are filled
                // in by the lookup-verified completion (khalti-return.php), never before.
                $stmt = $db->prepare("INSERT INTO payment_transactions
                    (booking_id, payment_method, amount, currency, gateway_order_ref, status)
                    VALUES (?, 'khalti', ?, 'NPR', ?, 'pending')");
                $stmt->execute([$booking_id, BOOKING_BASE_FEE, $gw['pidx']]);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Redirecting to Khalti for payment',
                    'data' => [
                        'booking_id' => $booking_id,
                        'estimated_cost' => $total_cost,
                        'currency' => 'NPR',
                        'payment_url' => $gw['payment_url'],
                        'payment_driver' => 'khalti'
                    ]
                ]);
                exit;
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment initiation request created',
                'data' => [
                    'booking_id' => $booking_id,
                    'estimated_cost' => $total_cost,
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
            
            // Booking is confirmed but NOT charging yet — the driver will start
            // the session (confirm_charging_payment) when they arrive/plug in.
            $stmt = $db->prepare("
                UPDATE bookings SET 
                    status = 'booked',
                    payment_status = 'completed'
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$booking_id, $user_id]);
            
            // Log payment transaction (charging_session_id is linked later at confirm_charging_payment)
            $transaction_id = 'TXN' . time() . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
            $stmt = $db->prepare("
                INSERT INTO payment_transactions (booking_id, charging_session_id, transaction_id, payment_method, amount, currency, status)
                VALUES (?, NULL, ?, 'wallet', ?, 'NPR', 'completed')
            ");
            $stmt->execute([$booking_id, $transaction_id, $booking['estimated_total_cost']]);
            
            $db->commit();
            
            // Re-fetch to return the updated status
            $stmt = $db->prepare("SELECT id, status, estimated_total_cost FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $updated = $stmt->fetch();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment confirmed. Booking reserved — the station will start your session when you arrive.',
                'data' => $updated
            ]);
            exit;
        }

        // Action: confirm_charging_payment — driver confirms payment, session starts
        if ($action === 'confirm_charging_payment') {
            $booking_id = intval($input['booking_id'] ?? 0);
            $battery_percent = intval($input['battery_percent'] ?? 0);

            if ($battery_percent < 1 || $battery_percent > 100) {
                echo json_encode(['status' => 'error', 'message' => 'Battery percentage must be between 1 and 100.']);
                exit;
            }

            // Verify booking
            $stmt = $db->prepare("
                SELECT b.*, c.wattage_kw, c.station_id, c.id as charger_id
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                WHERE b.id = ? AND b.user_id = ? AND b.status = 'booked'
            ");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                echo json_encode(['status' => 'error', 'message' => 'Booking not found or no longer eligible.']);
                exit;
            }

            if ($booking['arrival_deadline'] && strtotime($booking['arrival_deadline']) < time()) {
                echo json_encode(['status' => 'error', 'message' => 'Your reservation has expired.']);
                exit;
            }

            $capacity = floatval($booking['car_full_capacity_kwh']);
            $wattage = floatval($booking['wattage_kw']);
            // TODO: Known limitation — billing assumes session charges to 100% (no end-battery-% capture). See audit #8.
            $kwh_needed = (100 - $battery_percent) / 100 * $capacity;
            $charge_time_minutes = ceil($kwh_needed / $wattage * 60);
            $charging_cost = $kwh_needed * ELECTRICITY_RATE_PER_KWH;
            $total_cost = BOOKING_BASE_FEE + $charging_cost;

            $db->beginTransaction();

            // Update booking to charging
            $stmt = $db->prepare("
                UPDATE bookings SET
                    car_current_battery_percent = ?,
                    calculated_charge_time_minutes = ?,
                    estimated_total_cost = ?,
                    base_fee = ?,
                    session_ends_at = DATE_ADD(DATE_ADD(NOW(), INTERVAL 5 MINUTE), INTERVAL ? MINUTE),
                    status = 'charging'
                WHERE id = ?
            ");
            $stmt->execute([$battery_percent, $charge_time_minutes, $total_cost, BOOKING_BASE_FEE, $charge_time_minutes, $booking_id]);

            // Update charger status
            $stmt = $db->prepare("UPDATE chargers SET status = 'charging' WHERE id = ?");
            $stmt->execute([$booking['charger_id']]);

            // Create charging session
            $stmt = $db->prepare("
                INSERT INTO charging_sessions (booking_id, start_time, battery_start_percent, per_kwh_rate, payment_status)
                VALUES (?, NOW(), ?, ?, 'completed')
            ");
            $stmt->execute([$booking_id, $battery_percent, ELECTRICITY_RATE_PER_KWH]);

            // Insert second payment_transactions row for the charging fee.
            // ponytail: suffix distinguishes it from the reservation fee transaction
            // (both would otherwise collide on 'TXN{time}{booking_id}' within the same second).
            // The reservation fee (BOOKING_BASE_FEE) was already recorded as its own
            // transaction in confirm_payment — do NOT include it here again.
            $transaction_id = 'TXN' . time() . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . '-CHG';
            $stmt = $db->prepare("
                INSERT INTO payment_transactions (booking_id, transaction_id, payment_method, amount, currency, status)
                VALUES (?, ?, 'wallet', ?, 'NPR', 'completed')
            ");
            $stmt->execute([$booking_id, $transaction_id, $charging_cost]);

            // Notify driver
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details)
                VALUES (?, 'session_started', 'booking', ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $booking_id,
                "Charging session started. Total cost: NPR " . number_format($total_cost, 2) . " (NPR " . number_format(BOOKING_BASE_FEE, 2) . " reservation + NPR " . number_format($charging_cost, 2) . " charging)."
            ]);

            $db->commit();

            echo json_encode(['status' => 'success', 'message' => 'Charging session started successfully']);
            exit;
        }

        // Action: initiate_charging_payment — driver arrives, wants to start charging
        if ($action === 'initiate_charging_payment') {
            $booking_id = intval($input['booking_id'] ?? 0);
            $battery_percent = intval($input['battery_percent'] ?? 0);

            if ($battery_percent < 1 || $battery_percent > 100) {
                echo json_encode(['status' => 'error', 'message' => 'Battery percentage must be between 1 and 100.']);
                exit;
            }

            // Verify booking belongs to this driver and is in 'booked' state
            $stmt = $db->prepare("
                SELECT b.*, c.wattage_kw, c.station_id
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                WHERE b.id = ? AND b.user_id = ? AND b.status = 'booked'
            ");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                echo json_encode(['status' => 'error', 'message' => 'Booking not found or not eligible to start charging.']);
                exit;
            }

            // Check arrival deadline
            if ($booking['arrival_deadline'] && strtotime($booking['arrival_deadline']) < time()) {
                echo json_encode(['status' => 'error', 'message' => 'Your reservation has expired. Please book again.']);
                exit;
            }

            $capacity = floatval($booking['car_full_capacity_kwh']);
            $wattage = floatval($booking['wattage_kw']);
            $kwh_needed = (100 - $battery_percent) / 100 * $capacity;
            $charge_time_minutes = ceil($kwh_needed / $wattage * 60);
            $charging_cost = $kwh_needed * ELECTRICITY_RATE_PER_KWH;

            echo json_encode([
                'status' => 'success',
                'message' => 'Charging cost calculated',
                'data' => [
                    'booking_id' => $booking_id,
                    'charging_cost' => round($charging_cost, 2),
                    'charge_time_minutes' => $charge_time_minutes,
                    'kwh_needed' => round($kwh_needed, 2),
                    'currency' => 'NPR'
                ]
            ]);
            exit;
        }

        // Action: stop_session — driver stops their own active charging session early
        if ($action === 'stop_session') {
            $booking_id = intval($input['booking_id'] ?? 0);

            // Ownership + state check: driver's own booking, must be charging
            $stmt = $db->prepare("
                SELECT b.*, c.station_id, c.id as charger_id
                FROM bookings b
                JOIN chargers c ON b.charger_id = c.id
                WHERE b.id = ? AND b.user_id = ? AND b.status = 'charging'
            ");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                echo json_encode(['status' => 'error', 'message' => 'No active charging session found for this booking.']);
                exit;
            }

            $db->beginTransaction();

            // Mark booking as 'stopped' (distinct from 'completed' for reporting).
            // payment_status = 'completed' so it still appears in receipts/invoices.
            $stmt = $db->prepare("UPDATE bookings SET status = 'stopped', payment_amount = estimated_total_cost, payment_status = 'completed' WHERE id = ?");
            $stmt->execute([$booking_id]);

            // End the charging session now
            $stmt = $db->prepare("UPDATE charging_sessions SET end_time = NOW(), payment_status = 'completed' WHERE booking_id = ?");
            $stmt->execute([$booking_id]);

            // Release the charger
            $stmt = $db->prepare("UPDATE chargers SET status = 'available' WHERE id = ?");
            $stmt->execute([$booking['charger_id']]);

            // Notify driver (no refund)
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details)
                VALUES (?, 'session_stopped', 'booking', ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $booking_id,
                "Charging stopped early. Payment already made is NOT refunded."
            ]);

            $db->commit();
            echo json_encode(['status' => 'success', 'message' => 'Charging stopped. No refund issued.']);
            exit;
        }
        
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
            
            if ($action === 'complete_session') {
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
        Csrf::validate();
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