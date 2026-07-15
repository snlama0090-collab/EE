<?php
/**
 * Lazy tick engine — checks for overdue charging sessions and completes them.
 * Called piggyback on every request to api/bookings.php (and optionally from
 * a Windows Scheduled Task hitting api/cron/tick.php).
 *
 * ponytail: no cron on XAMPP — tick piggybacks on API traffic.
 */

function tickChargingSessions($db) {
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