<?php
require_once dirname(__DIR__, 3) . '/app/config/config.php';
require_once dirname(__DIR__, 3) . '/app/helpers/Auth.php';

Auth::requireUserType('owner');
$user_id = Auth::getCurrentUserId();
$db = getDB();

// Get bookings for owner's stations
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
$bookings = $stmt->fetchAll();
?>

<div class="bookings-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2>📋 Reservation Logs & Active Sessions</h2>
        <button class="btn btn-secondary" onclick="loadSection('bookings')">
            <i class="fas fa-sync"></i> Refresh List
        </button>
    </div>

    <div class="dashboard-section-card">
        <?php if (count($bookings) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Driver Details</th>
                            <th>Station / Charger</th>
                            <th>Energy Params</th>
                            <th>Cost Summary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): 
                            $status = $booking['status'];
                            $status_badge = 'badge-info';
                            if ($status === 'completed') $status_badge = 'badge-success';
                            elseif ($status === 'cancelled') $status_badge = 'badge-danger';
                            elseif ($status === 'charging') $status_badge = 'badge-warning';
                        ?>
                        <tr>
                            <td>#<?php echo $booking['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($booking['user_name']); ?></strong>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 4px;">
                                    📞 <?php echo htmlspecialchars($booking['user_phone'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($booking['station_name']); ?></div>
                                <div style="font-size: 11px; color: var(--gray); margin-top: 2px;">
                                    Charger #<?php echo $booking['charger_number']; ?> (<?php echo htmlspecialchars($booking['charger_type']); ?> - <?php echo $booking['wattage_kw']; ?>kW)
                                </div>
                            </td>
                            <td>
                                <div>Start Bat: <strong><?php echo $booking['car_current_battery_percent']; ?>%</strong></div>
                                <?php if ($status === 'completed'): ?>
                                    <div style="font-size: 11px; color: var(--secondary); margin-top: 2px;">
                                        Consumed: <strong><?php echo number_format($booking['kwh_consumed'], 2); ?> kWh</strong>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>Est: NPR <?php echo number_format($booking['estimated_total_cost'], 2); ?></div>
                                <?php if ($booking['payment_amount']): ?>
                                    <div style="font-size: 12px; color: var(--secondary); font-weight: 600; margin-top: 2px;">
                                        Paid: NPR <?php echo number_format($booking['payment_amount'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_badge; ?>"><?php echo $status; ?></span>
                            </td>
                            <td>
                                <?php if ($status === 'booked'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="updateSession(<?php echo $booking['id']; ?>, 'start_session')">
                                        <i class="fas fa-play"></i> Start Session
                                    </button>
                                <?php elseif ($status === 'charging'): ?>
                                    <button class="btn btn-submit btn-sm" style="background:linear-gradient(135deg, #FF9500 0%, #E68500 100%);" onclick="updateSession(<?php echo $booking['id']; ?>, 'complete_session')">
                                        <i class="fas fa-stop"></i> Stop / Bill
                                    </button>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--gray);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--gray);">
                <i class="fas fa-receipt" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                <h3>No Reservations Found</h3>
                <p>When drivers book chargers at your stations, they will show up here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

