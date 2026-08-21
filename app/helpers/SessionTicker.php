<?php
/**
 * Lazy tick engine — checks for overdue charging sessions and completes them.
 * Called piggyback on every request to api/bookings.php (and optionally from
 * a Windows Scheduled Task hitting api/cron/tick.php).
 *
 * ponytail: no cron on XAMPP — tick piggybacks on API traffic.
 */

/**
 * Auto-cancel abandoned bookings past their arrival deadline.
 * For each cancelled booking, log a notification for the driver.
 */
function cancelExpiredBookings($db) {
    // Select bookings that have expired their arrival deadline
    $stmt = $db->prepare("
        SELECT id, user_id, charger_id
        FROM bookings
        WHERE status IN ('pending_payment', 'booked')
          AND arrival_deadline IS NOT NULL
          AND arrival_deadline < NOW()
        LIMIT 50
    ");
    $stmt->execute();
    $expired = $stmt->fetchAll();

    foreach ($expired as $booking) {
        $db->beginTransaction();
        try {
            // Cancel the booking (guarded so concurrent ticks can't double-cancel)
            $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status IN ('pending_payment', 'booked')");
            $stmt->execute([$booking['id']]);

            // If another tick already cancelled it, skip
            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                continue;
            }

            // Notify the driver (plain-text details — notifications.php renders it raw)
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, resource_type, resource_id, details)
                VALUES (?, 'booking_expired', 'booking', ?, ?)
            ");
            $stmt->execute([
                $booking['user_id'],
                $booking['id'],
                'Your reservation expired because you did not arrive by the deadline.'
            ]);

            $db->commit();
            log_message('INFO', "Auto-cancelled expired booking #{$booking['id']}");
        } catch (Exception $e) {
            $db->rollBack();
            log_message('ERROR', "Auto-cancel failed for booking #{$booking['id']}: " . $e->getMessage());
        }
    }
}

function tickChargingSessions($db) {
    // Auto-cancel abandoned bookings past their arrival deadline
    cancelExpiredBookings($db);

    // Reconcile orphaned chargers: stuck in 'charging' with no active booking
    // for over 6 hours (grace period covers owner walk-up manual overrides).
    $stmt = $db->prepare("
        UPDATE chargers c
        LEFT JOIN bookings b ON b.charger_id = c.id AND b.status IN ('booked', 'charging')
        SET c.status = 'available'
        WHERE c.status = 'charging'
          AND b.id IS NULL
          AND c.updated_at <= DATE_SUB(NOW(), INTERVAL 6 HOUR)
    ");
    $stmt->execute();

    // Find any charging sessions that have exceeded their session_ends_at
    $stmt = $db->prepare("
        SELECT b.id as booking_id, b.charger_id, c.station_id,
               b.car_full_capacity_kwh, b.car_current_battery_percent,
               b.base_fee
        FROM bookings b
        JOIN chargers c ON b.charger_id = c.id
        WHERE b.status = 'charging'
          AND b.session_ends_at IS NOT NULL
          AND b.session_ends_at <= NOW()
        LIMIT 10
    ");
    $stmt->execute();
    $overdue = $stmt->fetchAll();

    if (empty($overdue)) {
        return;
    }

    foreach ($overdue as $booking) {
        $db->beginTransaction();

        try {
            // Get the charging session row
            $stmt2 = $db->prepare("SELECT id, start_time, battery_start_percent FROM charging_sessions WHERE booking_id = ?");
            $stmt2->execute([$booking['booking_id']]);
            $session = $stmt2->fetch();

            if (!$session) {
                $db->rollBack();
                continue;
            }

            $start_time = strtotime($session['start_time']);
            $end_time = time();
            $duration_minutes = max(1, ceil(($end_time - $start_time) / 60));

            $capacity = floatval($booking['car_full_capacity_kwh']);
            $start_pct = intval($session['battery_start_percent']);

            // kWh consumed
            $kwh = (100 - $start_pct) / 100 * $capacity;
            $electricity_cost = $kwh * ELECTRICITY_RATE_PER_KWH;
            $total_amount = floatval($booking['base_fee']) + $electricity_cost;

            // Update charging_sessions
            $stmt3 = $db->prepare("
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
            $stmt3->execute([$kwh, $duration_minutes, $electricity_cost, $total_amount, $booking['booking_id']]);

            // Update booking — guard with status check to prevent double-completion
            // ponytail: race-safe — only transitions if still 'charging'
            $stmt4 = $db->prepare("
                UPDATE bookings SET 
                    status = 'completed',
                    payment_amount = ?,
                    payment_status = 'completed'
                WHERE id = ? AND status = 'charging'
            ");
            $stmt4->execute([$total_amount, $booking['booking_id']]);

            // If no rows affected, another tick already completed this booking
            if ($stmt4->rowCount() === 0) {
                $db->rollBack();
                continue;
            }

            // Release the charger
            $stmt5 = $db->prepare("UPDATE chargers SET status = 'available' WHERE id = ?");
            $stmt5->execute([$booking['charger_id']]);

            // Update station stats
            $stmt6 = $db->prepare("
                UPDATE stations SET 
                    total_bookings = total_bookings + 1,
                    total_revenue = total_revenue + ?,
                    total_kwh_consumed = total_kwh_consumed + ?
                WHERE id = ?
            ");
            $stmt6->execute([$total_amount, $kwh, $booking['station_id']]);

            $db->commit();

            log_message('INFO', "SessionTicker auto-completed booking #{$booking['booking_id']}");
        } catch (Exception $e) {
            $db->rollBack();
            log_message('ERROR', "SessionTicker failed for booking #{$booking['booking_id']}: " . $e->getMessage());
        }
    }
}